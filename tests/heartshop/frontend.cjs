"use strict";
// Offline regression tests: production scripts, synthetic DOM/storage, no network.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { webcrypto } = require('node:crypto');
const root = path.resolve(__dirname, '../..');
const source = name => fs.readFileSync(path.join(root, 'public/heartshop/assets', name), 'utf8');
function storage() {
  const data = new Map();
  return { data, getItem:k=>data.get(k)??null, setItem:(k,v)=>data.set(k,v), removeItem:k=>data.delete(k) };
}
function page(file, store = storage(), lang = 'pl', dataset = {}) {
  const nodes = new Map();
  function element(id) {
    if (!nodes.has(id)) nodes.set(id, {
      textContent:'', value:'', checked:false, hidden:false, disabled:false,
      dataset:{}, style:{}, classList:{toggle(){}}, handlers:{},
      addEventListener(type,fn) { this.handlers[type]=fn; }, setAttribute(){},
      querySelector(sel) { return sel.includes(':checked') ? {value:'kurier'} : element(id+sel); },
      querySelectorAll(){return [];}, append(){}, focus(){}, scrollIntoView(){},
    });
    return nodes.get(id);
  }
  const redirects=[];
  const context = {
    URLSearchParams, Date, JSON, crypto:webcrypto, Uint8Array, TextEncoder, CSS:{escape:x=>x},
    location:{search:'',pathname:'/heartshop/'+file,assign:u=>redirects.push(u)},
    document:{documentElement:{lang,dataset,style:{setProperty(){}}},getElementById:element,querySelectorAll:()=>[],createElement:()=>element('created'),head:{append(){}}},
    sessionStorage:store, fetch:()=>new Promise(()=>{}), setTimeout(){},
  };
  context.window=context;
  vm.createContext(context);
  vm.runInContext(source('i18n.js'),context);
  return {context,element,store,redirects,run:name=>vm.runInContext(source(name),context)};
}
const order={name:'TEST',number:'TEST-1001',total:6299,email:'t…@example.invalid'};
test('review is held, never promised paid, mailed or shipped',()=>{
  const p=page('dziekujemy.html');p.run('thanks.js');p.context.show('review',order);
  assert.match(p.element('text').textContent,/sprawdzenia/i);
  assert.doesNotMatch(p.element('text').textContent,/jest opłacone|wysłaliśmy|Paczkę nadamy/);
  assert.match(p.element('text').textContent,/Nie płać ponownie/);
});
test('paid does not claim email delivery or a hard-coded shipping deadline',()=>{
  const p=page('dziekujemy.html');p.run('thanks.js');p.context.show('paid',order);
  assert.match(p.element('text').textContent,/jest opłacone/);
  assert.doesNotMatch(p.element('text').textContent,/wysłaliśmy|1–2/);
});
test('canceled is not a guarantee that no money was charged',()=>{
  const p=page('dziekujemy.html');p.run('thanks.js');p.context.show('canceled',order);
  assert.doesNotMatch(p.element('text').textContent,/Nic nie zostało pobrane/);
});
test('one attempt token survives retry and reload; changed data fails closed', async()=>{
  const p=page('zamowienie.html');p.run('attempt.js');
  const payload={qty:1,email:'test@example.invalid'};
  const first=await p.context.HeartshopAttempt.begin(payload);
  assert.match(first,/^[a-f0-9]{32}$/);
  assert.equal(await p.context.HeartshopAttempt.begin(payload),first);
  const reload=page('zamowienie.html',p.store);reload.run('attempt.js');
  assert.equal(await reload.context.HeartshopAttempt.begin(payload),first);
  await assert.rejects(()=>reload.context.HeartshopAttempt.begin({...payload,qty:2}),/poprzedni/i);
});
test('concurrent first submissions share one token without an await inside storage claim',async()=>{
  const p=page('zamowienie.html');p.run('attempt.js');
  const keys=await Promise.all(Array.from({length:30},()=>p.context.HeartshopAttempt.begin({qty:1})));
  assert.equal(new Set(keys).size,1);
});
test('attempt storage contains a digest, not another copy of contact data',async()=>{
  const p=page('zamowienie.html');p.run('attempt.js');
  await p.context.HeartshopAttempt.begin({email:'private@example.invalid'});
  assert.doesNotMatch(p.store.getItem('heartshop-attempt:/heartshop/'),/private@example.invalid/);
});
test('broken or unavailable attempt storage cannot silently create another payment',async()=>{
  const p=page('zamowienie.html');p.run('attempt.js');
  p.store.setItem('heartshop-attempt:/heartshop/','bad json');
  await assert.rejects(()=>p.context.HeartshopAttempt.begin({qty:1}));
  const broken=page('zamowienie.html',{getItem(){throw Error('denied');},setItem(){throw Error('denied');},removeItem(){}});
  broken.run('attempt.js');await assert.rejects(()=>broken.context.HeartshopAttempt.begin({qty:1}));
});
async function checkout(store, file='zamowienie.html', lang='pl') {
  const p=page(file,store,lang);p.run('attempt.js');p.run('checkout.js');
  vm.runInContext(`cfg={product:{price:4900,max_qty:10},delivery:{kurier:{price:1699}},freeShippingFrom:null,geowidgetToken:''}`,p.context);
  for (const [k,v] of Object.entries({email:'test@example.invalid',phone:'500600700',name:'TEST Person',street:'Test 1',postcode:'00-001',city:'Test'})) p.element(k).value=v;
  p.element('terms').checked=true;
  return p;
}
async function closedModal(status,event='onCancel') {
  const p=await checkout();let calls=0;
  p.context.setTimeout=fn=>fn();
  p.context.fetch=async u=>{
    if(String(u).startsWith('api/status.php')){calls++;return {ok:true,json:async()=>({status})};}
    return {ok:true,status:200,json:async()=>({orderId:'hs_'+'a'.repeat(24),redirectUrl:'https://checkout.example.invalid/x'})};
  };
  let callbacks;
  vm.runInContext('sdk=null',p.context);
  p.context.Paymove={openCheckout:(u,c)=>{callbacks=c;}};
  vm.runInContext('sdk=Promise.resolve(Paymove)',p.context);
  await p.element('order').handlers.submit({preventDefault(){}});
  await callbacks[event]();
  return {p,calls};
}
test('modal closed without a success signal still reaches thanks once the webhook paid',async()=>{
  for (const event of ['onCancel','onError']) {
    const {p,calls}=await closedModal('paid',event);
    assert.equal(calls,1);
    assert.match(p.redirects[0],/^dziekujemy\.html\?o=hs_a/);
  }
});
test('modal closed without payment keeps the buyer on the form after the bounded check',async()=>{
  const {p,calls}=await closedModal('pending');
  assert.equal(calls,4);
  assert.deepEqual(p.redirects,[]);
  assert.equal(p.element('pay').disabled,false);
});
test('actual submit retries a network failure with the identical header and payload',async()=>{
  const p=await checkout(), requests=[];
  p.context.fetch=async(u,options)=>{requests.push(options);throw Error('network');};
  const submit=()=>p.element('order').handlers.submit({preventDefault(){}});
  await submit();await submit();
  assert.equal(requests.length,2);
  assert.match(requests[0].headers['Idempotency-Key'],/^[a-f0-9]{32}$/);
  assert.equal(requests[0].headers['Idempotency-Key'],requests[1].headers['Idempotency-Key']);
  assert.equal(requests[0].body,requests[1].body);
  p.element('email').value='changed@example.invalid';await submit();
  assert.equal(requests.length,2);
});
test('actual submit navigates a terminal retry to status, not another payment',async()=>{
  const p=await checkout();
  p.context.fetch=async()=>({ok:true,status:200,json:async()=>({orderId:'hs_'+'a'.repeat(24),status:'review'})});
  await p.element('order').handlers.submit({preventDefault(){}});
  assert.deepEqual(p.redirects,['dziekujemy.html?o=hs_'+'a'.repeat(24)]);
});
test('validation failure permits correction; unknown failure retains attempt',async()=>{
  const p=await checkout(),requests=[];
  p.context.fetch=async(u,options)=>{requests.push(options);return {ok:false,status:422,json:async()=>({errors:{email:'invalid'}})};};
  const submit=()=>p.element('order').handlers.submit({preventDefault(){}});
  await submit();p.element('email').value='corrected@example.invalid';await submit();
  assert.equal(requests.length,2);assert.notEqual(requests[0].headers['Idempotency-Key'],requests[1].headers['Idempotency-Key']);
});
const orderA='hs_'+'a'.repeat(24), orderB='hs_'+'b'.repeat(24);
const attemptOf=p=>JSON.parse(p.store.getItem('heartshop-attempt:/heartshop/'));
async function bound(store,orderId) {
  const p=page('zamowienie.html',store);p.run('attempt.js');
  const key=await p.context.HeartshopAttempt.begin({qty:1});
  p.context.HeartshopAttempt.remember(key,orderId);
  return p;
}
test('confirmed paid or canceled status ends only that order\'s own attempt',async()=>{
  for (const state of ['paid','canceled']) {
    const p=await bound(storage(),orderA);
    const own=page('dziekujemy.html',p.store);own.context.location.search='?o='+orderB;
    own.run('attempt.js');own.run('thanks.js');own.context.show(state,order);
    assert.equal(attemptOf(p).orderId,orderA,'another order never clears the attempt');
    const thanks=page('dziekujemy.html',p.store);thanks.context.location.search='?o='+orderA;
    thanks.run('attempt.js');thanks.run('thanks.js');thanks.context.show(state,order);
    assert.equal(p.store.getItem('heartshop-attempt:/heartshop/'),null,state);
  }
});
test('review and pending keep the attempt bound',async()=>{
  const p=await bound(storage(),orderA);
  const thanks=page('dziekujemy.html',p.store);thanks.context.location.search='?o='+orderA;
  thanks.run('attempt.js');thanks.run('thanks.js');thanks.context.show('review',order);
  assert.equal(attemptOf(p).orderId,orderA);
});
test('a second purchase after a paid order is a new attempt, not the old order',async()=>{
  const keys=[];
  const fetch=async(u,o)=>{keys.push(o.headers['Idempotency-Key']);return {ok:true,status:200,json:async()=>({orderId:keys.length===1?orderA:orderB,status:'paid'})};};
  const first=await checkout();first.context.fetch=fetch;
  await first.element('order').handlers.submit({preventDefault(){}});
  assert.equal(attemptOf(first).orderId,orderA);
  const thanks=page('dziekujemy.html',first.store);thanks.context.location.search='?o='+orderA;
  thanks.run('attempt.js');thanks.run('thanks.js');thanks.context.show('paid',order);
  const again=await checkout(first.store);again.context.fetch=fetch; // a fresh page load, same tab
  await again.element('order').handlers.submit({preventDefault(){}});
  assert.equal(keys.length,2);assert.notEqual(keys[0],keys[1]);
  assert.deepEqual(again.redirects,['dziekujemy.html?o='+orderB]);
});
test('retry after the operator canceled the payment opens exactly one fresh attempt',async()=>{
  const p=await checkout(),keys=[];
  p.context.fetch=async(u,o)=>{keys.push(o.headers['Idempotency-Key']);
    return keys.length===1
      ? {ok:false,status:409,json:async()=>({code:'payment_closed',orderId:orderA,error:'closed'})}
      : {ok:true,status:200,json:async()=>({orderId:orderB,status:'paid'})};};
  await p.element('order').handlers.submit({preventDefault(){}});
  assert.equal(keys.length,2);assert.notEqual(keys[0],keys[1]);
  assert.deepEqual(p.redirects,['dziekujemy.html?o='+orderB]);
});
test('a repeated payment_closed never loops',async()=>{
  const p=await checkout();let calls=0;
  p.context.fetch=async()=>{calls++;return {ok:false,status:409,json:async()=>({code:'payment_closed',orderId:orderA,error:'closed'})};};
  await p.element('order').handlers.submit({preventDefault(){}});
  assert.equal(calls,2);assert.deepEqual(p.redirects,[]);
});
test('changed data is allowed only after the previous order is confirmed canceled',async()=>{
  for (const [status,allowed] of [['canceled',true],['pending',false],['review',false]]) {
    const p=await checkout(),orders=[],statusChecks=[];
    p.context.fetch=async(u,o)=>{
      if (u.startsWith('api/status.php')) { statusChecks.push(u);return {ok:true,json:async()=>({status})}; }
      orders.push(o.headers['Idempotency-Key']);
      return {ok:false,status:503,json:async()=>({orderId:orderA,error:'uncertain'})};
    };
    const submit=()=>p.element('order').handlers.submit({preventDefault(){}});
    await submit();
    p.element('email').value='changed@example.invalid';await submit();
    assert.deepEqual(statusChecks,['api/status.php?o='+orderA],status);
    assert.equal(orders.length,allowed?2:1,status);
    if (allowed) assert.notEqual(orders[0],orders[1]);
  }
});
test('English checkout uses the shared API, English pages and the shared attempt',async()=>{
  const store=storage(), p=await checkout(store,'en/order.html','en'), urls=[];
  p.context.fetch=async(u,options)=>{urls.push(u);return {ok:true,status:200,json:async()=>({orderId:'hs_'+'a'.repeat(24),status:'paid'})};};
  await p.element('order').handlers.submit({preventDefault(){}});
  assert.deepEqual(urls,['../api/order.php']);
  assert.deepEqual(p.redirects,['thank-you.html?o=hs_'+'a'.repeat(24)]);
  assert.deepEqual([...store.data.keys()].filter(k=>k.startsWith('heartshop-attempt:')),['heartshop-attempt:/heartshop/']);
});
test('English checkout translates server errors and field errors',async()=>{
  const p=await checkout(undefined,'en/order.html','en');
  p.context.fetch=async()=>({ok:false,status:429,json:async()=>({error:'Zbyt wiele prób. Odczekaj kilka minut.',code:'rate_limited'})});
  await p.element('order').handlers.submit({preventDefault(){}});
  assert.equal(p.element('form-alert').textContent,'Too many attempts. Please wait a few minutes.');
  const I=p.context.HeartshopI18n;
  assert.deepEqual({...I.fieldErrors({point:'Nie ma Paczkomatu o tym kodzie.',qty:'x'})},{point:'Choose a Paczkomat.',qty:'Choose a quantity within the limit.'});
  assert.equal(I.local('delivery.kurier','Kurier InPost'),'InPost courier');
});
test('English thanks page keeps the order id on the language switch',()=>{
  const p=page('en/thank-you.html',storage(),'en');p.context.location.search='?o=hs_'+'b'.repeat(24);
  p.run('attempt.js');p.run('thanks.js');p.context.show('review',order);
  assert.equal(p.element('title').textContent,'The payment needs checking');
  assert.equal(p.element('lang-alt').search,'?o=hs_'+'b'.repeat(24));
});
test('landing sandbox notice follows server configuration',async()=>{
  const p=page('index.html');
  p.context.addEventListener=()=>{};
  p.context.document.querySelector=()=>p.element('header');
  p.context.fetch=async()=>({ok:true,json:async()=>({sandbox:false,product:{price:4900},delivery:{},freeShippingFrom:null})});
  p.run('landing.js');
  await new Promise(resolve=>setImmediate(resolve));
  assert.equal(p.element('demo-banner').hidden,true);
});

test('HTTPS rules redirect only to listed hosts and do not trust client forwarding headers',()=>{
  const rules=fs.readFileSync(path.join(root,'public/heartshop/.htaccess'),'utf8');
  assert.match(rules,/RewriteCond %\{HTTPS\} !=on/);
  assert.match(rules,/\^\(shop\\\.example\\\.com\|staging\\\.shop\\\.example\\\.com\)\$/);
  assert.doesNotMatch(rules,/https:\/\/%\{HTTP_HOST\}|X-Forwarded-Proto/);
  assert.match(rules,/Referrer-Policy "no-referrer"/);
});

test('shipped-product checkout sends exactly the pre-voucher payload, key order included',async()=>{
  const p=await checkout(),bodies=[];
  p.context.fetch=async(u,o)=>{bodies.push(JSON.parse(o.body));return {ok:true,status:200,json:async()=>({orderId:orderA,status:'paid'})};};
  await p.element('order').handlers.submit({preventDefault(){}});
  assert.deepEqual(Object.keys(bodies[0]),['qty','email','phone','name','delivery','invoice','terms','street','postcode','city']);
});
async function voucherCheckout(store=storage()) {
  const p=page('voucher/zamowienie.html',store,'pl',{root:'../',offer:'masaz-60'});p.run('attempt.js');p.run('checkout.js');
  vm.runInContext(`cfg={product:{id:'masaz-60',type:'voucher',price:25000,max_qty:5},delivery:{kurier:{price:1699}},freeShippingFrom:15000,geowidgetToken:''}`,p.context);
  for (const [k,v] of Object.entries({email:'gift@example.invalid',name:'TEST Giver',recipient:'Ania',dedication:'Na spokój'})) p.element(k).value=v;
  p.element('terms').checked=true;
  return p;
}
test('voucher checkout needs no phone or address and names its offer',async()=>{
  const store=storage(),p=await voucherCheckout(store),calls=[];
  p.context.fetch=async(u,o)=>{calls.push([u,JSON.parse(o.body)]);return {ok:true,status:200,json:async()=>({orderId:orderA,status:'paid'})};};
  await p.element('order').handlers.submit({preventDefault(){}});
  assert.equal(calls.length,1,'no phone, delivery or address blocks the voucher');
  const [url,body]=calls[0];
  assert.equal(url,'../api/order.php');
  assert.deepEqual({...body},{offer:'masaz-60',qty:1,email:'gift@example.invalid',name:'TEST Giver',recipient:'Ania',dedication:'Na spokój',invoice:false,terms:true});
  assert.deepEqual(p.redirects,['dziekujemy.html?o='+orderA]);
  assert.ok([...store.data.keys()].includes('heartshop-attempt:/heartshop/voucher/'),'voucher attempts never collide with the candle\'s');
});
test('voucher total has no delivery line and no free-shipping nudge',async()=>{
  const p=await voucherCheckout();
  vm.runInContext('qty=2;render()',p.context);
  assert.equal(p.element('t-total').textContent,p.context.HeartshopI18n.money(50000));
  assert.equal(p.element('nudge').textContent,'','free-shipping nudge never written');
  assert.equal(p.element('t-ship').textContent,'','delivery line never written');
});
test('paid voucher thanks page leads to the printable voucher',()=>{
  const p=page('voucher/dziekujemy.html',storage(),'pl',{root:'../'});p.context.location.search='?o='+orderA;
  p.run('attempt.js');p.run('thanks.js');p.context.show('paid',{...order,type:'voucher'});
  assert.equal(p.element('action').href,'twoj-voucher.html?o='+orderA);
  assert.match(p.element('text').textContent,/wydrukować/);
  assert.doesNotMatch(p.element('text').textContent,/wysył|paczk/i);
});
test('pages in an offer folder reach the shared API',()=>{
  const p=page('voucher/index.html',storage(),'pl',{root:'../',offer:'masaz-60'});
  assert.equal(p.context.HeartshopI18n.api,'../api/');
  assert.equal(p.context.HeartshopI18n.offer,'masaz-60');
  const candle=page('index.html');
  assert.equal(candle.context.HeartshopI18n.api,'api/');
  assert.equal(candle.context.HeartshopI18n.offer,null);
});

test('new box and old order share attempt storage so an unresolved old payment cannot be bypassed', async()=>{
  const store=storage(), old=page('zamowienie.html',store);old.run('attempt.js');
  await old.context.HeartshopAttempt.begin({qty:1});
  const box=page('zamowienie.html',store,'pl',{offer:'magic-box'});box.run('attempt.js');
  await assert.rejects(()=>box.context.HeartshopAttempt.begin({offer:'magic-box',qty:1}),/poprzedni/i);
});

test('pending historical candle stays identified after a slow response',()=>{
  const p=page('dziekujemy.html'); const nodes={'.brand':{},'.thanks-photo':{},'.demo-context':{}};
  p.context.document.querySelector=sel=>nodes[sel]||null;
  p.context.document.title='Order'; p.run('thanks.js');
  p.context.identify({type:'shipping',offer:'1',demo:false}); p.context.show('slow');
  assert.equal(nodes['.brand'].textContent,'Magiczna świeca');
  assert.equal(nodes['.thanks-photo'].src,'assets/magic-box.webp');
  assert.equal(nodes['.demo-context'].hidden,true);
});
