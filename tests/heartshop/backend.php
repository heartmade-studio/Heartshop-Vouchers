<?php
// Offline production-function regression tests. Synthetic credentials, no real HTTP/mail.
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$tmpRoot = sys_get_temp_dir();
$private = $tmpRoot . '/heartshop-test-' . bin2hex(random_bytes(6));
mkdir($private, 0700, true);
file_put_contents($private.'/.env', "PAYMOVE_API_KEY=synthetic-test-only\nPAYMOVE_PRODUCT_ID=fixture\nPAYMOVE_WEBHOOK_SECRET=synthetic-signing-only\nMAIL_FROM=shop@example.invalid\nMERCHANT_EMAIL=merchant@example.invalid\nSHOP_ORIGIN=https://shop.example.com\n");
file_put_contents($private.'/.env', 'PANEL_PASSWORD_HASH='.password_hash('synthetic-panel-pass', PASSWORD_DEFAULT)."\n", FILE_APPEND);
chmod($private.'/.env', 0600);
putenv('HEARTSHOP_ENV_FILE='.$private.'/.env');
$_SERVER['DOCUMENT_ROOT']=$root;
require $root.'/public/heartshop/api/lib.php';
$passed=0;
function check(bool $ok, string $name): void { global $passed; if (!$ok) throw new RuntimeException('FAIL '.$name); echo 'PASS '.$name.PHP_EOL; $passed++; }
function rejected(callable $run, int $status, string $name): void {
    try { $run(); } catch (CheckoutProblem $e) { check($e->httpStatus === $status, $name); return; }
    throw new RuntimeException('FAIL expected rejection: '.$name);
}
try {
    check(function_exists('prepareCheckout'), 'checkout reliability functions exist');
    $input=['qty'=>1,'email'=>'test@example.invalid','phone'=>'500600700','name'=>'TEST Person','delivery'=>'kurier',
            'street'=>'Test 1','postcode'=>'00-001','city'=>'Test','invoice'=>false,'terms'=>true];
    $calls=0;
    $provider=function($id,$number,$order,$total) use (&$calls) { $calls++; return 'https://pay.sandbox.paymove.io/fixture/'.$id; };
    $key=str_repeat('a',32);
    $one=prepareCheckout($input,$key,$provider);
    $two=prepareCheckout($input,$key,$provider,null,fn()=>'INITIALIZED');
    check($one['orderId']===$two['orderId'] && $calls===1, 'same key reuses single provider creation and order');
    rejected(fn()=>prepareCheckout(array_replace($input,['qty'=>2]),$key,$provider),409,'same key changed payload rejected');
    rejected(fn()=>prepareCheckout(array_replace($input,['email'=>'bad']),$key,$provider),409,'existing key checked before validation');
    $order=findOrder($one['orderId']);
    check($order['data']['snapshot']['total']===$order['total'],'total has immutable snapshot');
    $config=cfg();$config['offers']['1']['price']=99900;$config['offers']['1']['name']='NEW PRODUCT';
    $snap=makeSnapshot($input,$config);
    check($snap['unit_price']===99900,'snapshot created from explicit config');
    check(!str_contains(orderSummary($order),'NEW PRODUCT'),'historical summary uses stored name');
    check(str_contains(orderSummary($order),'49,00'),'historical summary uses stored price');
    $legacy=$order;unset($legacy['data']['snapshot']);
    check(str_contains(orderSummary($legacy),'Brak historycznego'),'legacy summary declares missing breakdown');
    try { furgonetkaOrder($legacy); throw new RuntimeException('FAIL legacy export allowed'); }
    catch (LegacyOrderException) { check(true,'legacy export held instead of repriced'); }
    $export=furgonetkaOrder($order);
    check($export['products'][0]['priceGross']===49 && $export['shippingCost']===16.99,'Furgonetka snapshot values');
    check($export['products'][0]['taxRate']===23 && $export['shippingTaxRate']===23 && $export['invoiceAddress']===null,'Furgonetka gets VAT rates, no invoice data unless requested');
    $vatConfig=cfg();$vatConfig['tax_rate']=null;
    check(makeSnapshot($input,$vatConfig)['tax_rate']===null,'VAT-exempt seller stored in snapshot');
    $invoiced=$order;$invoiced['data']=array_replace($order['data'],['invoice'=>true,'company'=>'TEST Sp. z o.o.','nip'=>'5093068848','company_address'=>'ul. Polna 1/2, 80-300 Gdańsk']);
    $billing=furgonetkaOrder($invoiced)['invoiceAddress'];
    check($billing['company']==='TEST Sp. z o.o.' && $billing['nip']==='5093068848' && $billing['street']==='ul. Polna 1/2' && $billing['postcode']==='80-300' && $billing['city']==='Gdańsk','invoice address split for Furgonetka');
    check(parseCompanyAddress('Polna 1 80-300 Gdańsk')!==null,'company address without comma accepted');
    check(parseCompanyAddress('ul. Polna 1, Gdańsk')===null,'company address without postcode rejected');
    $failKey=str_repeat('b',32);$failCalls=0;
    $uncertain=function() use (&$failCalls) { $failCalls++;throw new RuntimeException('synthetic timeout'); };
    rejected(fn()=>prepareCheckout($input,$failKey,$uncertain),409,'provider timeout is uncertain');
    rejected(fn()=>prepareCheckout($input,$failKey,$uncertain),409,'uncertain retry held');
    check($failCalls===1,'uncertain provider operation never retried automatically');
    db()->exec("UPDATE orders SET status='paid' WHERE external_id='".$one['orderId']."'");
    $terminal=prepareCheckout($input,$key,$provider);
    check($terminal['status']==='paid' && !isset($terminal['redirectUrl']),'paid retry returns status without reopening payment');
    $stamp=(string)time();$body='{"status":"COMPLETED"}';
    $sig='t='.$stamp.',v1='.base64_encode(hash_hmac('sha256',$stamp.'.'.$body,'synthetic-signing-only',true));
    check(verifySignature($sig,$body),'valid raw-body signature');
    check(!verifySignature($sig,$body.' '),'mutated body rejected');
    check(!verifySignature('t=1,v1='.base64_encode(str_repeat('x',32)),$body),'stale signature rejected');
    check(validPaymentUrl('https://checkout.sandbox.paymove.io/a'),'approved HTTPS redirect');
    foreach (['https://pay.sandbox.paymove.io.evil.invalid/a','https://pay.paymove.io/a','http://checkout.sandbox.paymove.io/a','https://checkout.sandbox.paymove.io.evil.invalid/a','https://user@checkout.sandbox.paymove.io/a','https://checkout.sandbox.paymove.io:444/a'] as $url) {
        check(!validPaymentUrl($url),'unsafe payment redirect rejected');
    }
    $now=1800000000;
    for($i=0;$i<20;$i++) check(rateLimit('192.0.2.1',$now),'rate allowed '.$i);
    check(!rateLimit('192.0.2.1',$now),'per-IP rate limit');
    check(rateLimit('192.0.2.1',$now+600),'next bucket allowed');
    $spread=true;for($ip=0;$ip<25;$ip++) for($i=0;$i<20;$i++) $spread=$spread && rateLimit('198.51.100.'.$ip,$now+1200);
    check($spread,'many addresses cannot lock the shop with POSTs alone');
    check(($snap['delivery_eta']??null)===cfg()['delivery']['kurier']['eta'],'snapshot keeps promised dispatch time');
    $capNow=1900000000;$capCalls=0;
    $counting=function($id) use (&$capCalls) { $capCalls++; return 'https://pay.sandbox.paymove.io/fixture/'.$id; };
    for($i=0;$i<50;$i++) rejected(fn()=>prepareCheckout(array_replace($input,['email'=>'bad']),sprintf('%032x',10000+$i),$counting,$capNow),422,'invalid order before cap');
    for($i=0;$i<200;$i++) prepareCheckout($input,sprintf('%032x',20000+$i),$counting,$capNow);
    check($capCalls===200,'invalid orders do not consume the new-payment cap');
    rejected(fn()=>prepareCheckout($input,sprintf('%032x',30000),$counting,$capNow),429,'new-payment cap holds');
    check($capCalls===200,'capped attempt never reaches the provider');
    $again=prepareCheckout($input,sprintf('%032x',20000),$counting,$capNow,fn()=>'INITIALIZED');
    check(isset($again['redirectUrl']) && $capCalls===200,'existing attempt retry is not capped');
    check((fileperms($private.'/orders.sqlite') & 0777)===0600,'database created mode 0600');
    $bad=$root.'/public/heartshop/api/config.php';
    try { privateConfigPath($bad,$root);throw new RuntimeException('FAIL public path accepted'); }
    catch (InvalidArgumentException) { check(true,'webroot config rejected'); }
    foreach (['absent','unsafe-directory','unsafe-file'] as $case) {
        if ($case==='unsafe-directory') chmod($private,0755);
        if ($case==='unsafe-file') { chmod($private,0700);chmod($private.'/.env',0644); }
        clearstatcache();
        try { privateConfigPath($case==='absent'?$private.'/missing/.env':$private.'/.env',$root);throw new RuntimeException('FAIL unsafe path accepted'); }
        catch (InvalidArgumentException) { check(true,'private path fails closed: '.$case); }
    }
    chmod($private.'/.env',0600);clearstatcache();

    // ---------- vouchers ----------
    $vin=['offer'=>'masaz-60','qty'=>2,'email'=>'gift@example.invalid','name'=>'TEST Giver','recipient'=>'Ania','dedication'=>'Na spokojny wieczór','invoice'=>false,'terms'=>true];
    [$vo,$ve]=validateOrder($vin);
    check($ve===[] && $vo['offer']==='masaz-60' && $vo['recipient']==='Ania','voucher needs no phone, delivery or address');
    check(isset(validateOrder(array_replace($vin,['recipient'=>'   ']))[1]['recipient']),'blank voucher recipient rejected');
    foreach (['dedication'=>'Wejdź na https://x.example','recipient'=>'Ani www.x','name'=>'TEST evil.com/login'] as $field=>$value) {
        check(validateOrder(array_replace($vin,[$field=>$value]))[1][$field]==='Bez linków i adresów stron. Po kropce dodaj spację.',"link in $field rejected");
    }
    check(validateOrder(array_replace($vin,['dedication'=>'Wszystkiego dobrego. Ściskam! J.R.R. Tolkien']))[1]===[],'ordinary dedication with dots accepted');
    check(clean("A\u{202E}moc.live\u{0007} B")==='Amoc.live B','bidi and control characters stripped');
    check(validOrderId('hs_'.str_repeat('a',24)) && !validOrderId('hs_'.str_repeat('a',24)."\n") && !validOrderId(['x']),'order id format is strict');
    check(!isset($vo['phone']) && !isset($vo['street']),'voucher order stores no shipping data');
    check(validateOrder(array_replace($vin,['offer'=>'nope']))[1]===['offer'=>'Ten produkt nie jest już dostępny. Odśwież stronę.'],'unknown offer rejected');
    check(validateOrder(array_replace($vin,['qty'=>6]))[1]['qty']==='Wybierz od 1 do 5 sztuk.','per-offer quantity limit');
    check(validateOrder($input)[0]['offer']==='1','page without offer orders the default product');
    check(offer('1')['name']==='Magiczna świeca' && offer('magic-box')['name']==='Magiczne pudełko','new product has its own offer id and keeps historical product identity');
    check(validateOrder(array_replace($input,['offer'=>'magic-box']))[0]['offer']==='magic-box','box checkout selects its own offer');
    $vp=prepareCheckout($vin,str_repeat('c',32),$provider);
    $vorder=findOrder($vp['orderId']);
    check($vorder['total']===50000 && $vorder['data']['snapshot']['shipping']===0 && $vorder['data']['snapshot']['type']==='voucher','voucher total has no shipping');
    check($vorder['offer_type']==='voucher' && $vorder['data']['snapshot']['valid_months']===12 && $vorder['data']['snapshot']['path']==='voucher/','voucher snapshot keeps type, validity and page');
    check($vorder['data']['snapshot']['demo']===true,'new demo voucher records its identity');
    check($vorder['data']['snapshot']['test_payment']===true,'sandbox order records its test payment');
    $sandboxReal=$vorder; $sandboxReal['data']['snapshot']['demo']=false;
    $mail=voucherBuyerMail($sandboxReal,'Test','CODE');
    check(str_contains($mail,'płatności testowej') && !str_contains($mail,'Płatność dotarła'),'real offer paid in the sandbox is mailed as a test payment');
    $legacyVoucher=$vorder; unset($legacyVoucher['data']['snapshot']['demo'],$legacyVoucher['data']['snapshot']['test_payment']);
    $mail=voucherBuyerMail($legacyVoucher,'Test','CODE');
    check(!str_contains($mail,'demonstracyjny') && !str_contains($mail,'płatności testowej'),'historical voucher mail claims neither demo nor test status');
    // The pre-voucher implementation, verbatim: an attempt open during the deploy keeps its fingerprint.
    $old=array_intersect_key($input,array_flip(['qty','email','phone','name','delivery','point','point_label','street','postcode','city','invoice','company','nip','company_address','terms']));ksort($old);
    check(requestFingerprint($input)===hash('sha256',json_encode($old,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)),'candle fingerprint unchanged by new keys');
    $vevent=['externalId'=>$vp['orderId'],'status'=>'COMPLETED','price'=>'50000','paymentMethod'=>'BLIK','orderId'=>'pm-voucher'];
    $mailsBefore=(int)db()->querySingle('SELECT COUNT(*) FROM mail_outbox');
    check(applyPaymentEvent($vevent)['status']==='paid','voucher payment confirmed');
    $codes=vouchersFor($vp['orderId']);
    check(count($codes)===2 && $codes[0]['code']!==$codes[1]['code'],'one distinct code per voucher bought');
    check((bool)preg_match('/^[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{4}$/',$codes[0]['code']),'code is XXXX-XXXX without look-alike characters');
    $paidAt=(int)findOrder($vp['orderId'])['paid_at'];
    $expected=(new DateTimeImmutable('@'.$paidAt))->setTimezone(new DateTimeZone('Europe/Warsaw'))->modify('+12 months')->format('Y-m-d');
    check((new DateTimeImmutable('@'.$codes[0]['expires_at']))->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('Y-m-d H:i:s')===$expected.' 23:59:59','valid through the end of the day 12 months later');
    applyPaymentEvent($vevent);
    check(count(vouchersFor($vp['orderId']))===2,'duplicate webhook issues no extra codes');
    $vmails=[];$r=db()->query("SELECT * FROM mail_outbox WHERE order_external_id='{$vp['orderId']}' ORDER BY id");while($m=$r->fetchArray(SQLITE3_ASSOC))$vmails[]=$m;
    check(count($vmails)===2 && (int)db()->querySingle('SELECT COUNT(*) FROM mail_outbox')===$mailsBefore+2,'voucher payment queues buyer and seller mail once');
    check(str_contains($vmails[0]['body'],$vin['email']) && str_contains($vmails[0]['body'],'Voucher 2 · nr '.$codes[1]['code']) && str_contains($vmails[0]['body'],$codes[0]['code']),'buyer mail books by email and numbers each unit');
    check(str_contains($vmails[0]['body'],'https://shop.example.com/heartshop/voucher/twoj-voucher.html?o='.$vp['orderId']),'buyer mail links the printable voucher');
    check(!str_contains($vmails[0]['body'],'paczk') && !str_contains($vmails[0]['body'],'Wysyłamy'),'voucher mail promises no parcel');
    check(str_contains($vmails[0]['body'],'Dla: Ania') && str_contains($vmails[1]['body'],$vin['email']),'recipient in summary; seller sees the codes');
    $rv=prepareCheckout(array_replace($vin,['qty'=>1]),str_repeat('d',32),$provider);
    applyPaymentEvent(['externalId'=>$rv['orderId'],'status'=>'COMPLETED','price'=>'100','orderId'=>'pm-x']);
    check(findOrder($rv['orderId'])['status']==='review' && vouchersFor($rv['orderId'])===[],'review payment issues no codes');
    $rmail=db()->querySingle("SELECT body FROM mail_outbox WHERE order_external_id='{$rv['orderId']}'");
    check(str_contains($rmail,'nie zostały wydane'),'review mail says no codes were issued');
    $code=$codes[0]['code'];
    check(setVoucherUsed(strtolower(str_replace('-',' ',$code)),true)[0]===true,'redeem accepts the code as typed');
    check(setVoucherUsed($code,true)[1]==='already_used','a code is used only once');
    check(setVoucherUsed($code,false)[0]===true && setVoucherUsed($code,true)[0]===true,'undo makes it active again');
    check(setVoucherUsed('ZZZZ-ZZZZ',true)[1]==='unknown' && setVoucherUsed('nope',true)[1]==='invalid','unknown and malformed codes refused');
    check(setVoucherUsed($codes[1]['code'],true,$codes[1]['expires_at']+1)[1]==='expired','expired code refused');
    check(normalizeVoucherCode(' abcd2345 ')==='ABCD-2345' && normalizeVoucherCode(['x'])===null,'code normalisation');
    check(voucherExpiry(strtotime('2026-01-31 12:00 Europe/Warsaw'),12)===strtotime('2027-01-31 23:59:59 Europe/Warsaw'),'expiry date arithmetic');

    // ---------- panel ----------
    $pnow=2000000000;
    check(panelLogin('wrong','203.0.113.5',$pnow)===null,'wrong password refused');
    $token=panelLogin('synthetic-panel-pass','203.0.113.5',$pnow);
    check(is_string($token) && panelSessionValid($token,$pnow+60),'right password opens a session');
    check(!str_contains((string)db()->querySingle('SELECT token_hash FROM panel_sessions'),$token),'only a hash of the session token is stored');
    check(!panelSessionValid($token,$pnow+PANEL_TTL+120) && !panelSessionValid(($token[0]==='f' ? 'e' : 'f').substr($token,1),$pnow) && !panelSessionValid(null),'expired, forged and missing sessions refused');
    panelLogout($token);
    check(!panelSessionValid($token,$pnow+60),'logout ends the session');
    for($i=0;$i<8;$i++) panelLogin('wrong','203.0.113.5',$pnow);
    try { panelLogin('synthetic-panel-pass','203.0.113.5',$pnow); throw new RuntimeException('FAIL login limit'); }
    catch (PanelProblem $e) { check($e->httpStatus===429,'login attempts limited per address'); }
    check(is_string(panelLogin('synthetic-panel-pass','203.0.113.6',$pnow)),'another address still logs in');
    $list=panelOrders();
    $row=current(array_filter($list,fn($o)=>$o['id']===$vp['orderId']));
    check($row['type']==='voucher' && count($row['vouchers'])===2 && $row['recipient']==='Ania','panel lists the voucher order with codes');
    check(current(array_filter($list,fn($o)=>$o['id']===$one['orderId']))['type']==='shipping','panel lists the shipped order');
    panelUpdate($vp['orderId'],['note'=>'Ania dzwoniła','done'=>true,'invoice_done'=>false],$pnow);
    $updated=findOrder($vp['orderId']);
    check($updated['note']==='Ania dzwoniła' && (int)$updated['done_at']===$pnow && $updated['invoice_done_at']===null && $updated['status']==='paid','panel note and ticks never touch payment state');
    foreach ([['bad',['note'=>'x']],[$vp['orderId'],['done'=>'yes']],[$vp['orderId'],[]]] as [$id,$change]) {
        try { panelUpdate($id,$change); throw new RuntimeException('FAIL bad panel update'); } catch (PanelProblem) { check(true,'invalid panel update refused'); }
    }
    $before=(int)db()->querySingle('SELECT COUNT(*) FROM mail_outbox');
    panelResend($vp['orderId'],$pnow);
    $resent=db()->querySingle('SELECT body FROM mail_outbox ORDER BY id DESC LIMIT 1');
    check((int)db()->querySingle('SELECT COUNT(*) FROM mail_outbox')===$before+1 && str_contains($resent,$vin['email']),'resend queues the buyer mail again');
    try { panelResend($rv['orderId'],$pnow); throw new RuntimeException('FAIL resend review'); }
    catch (PanelProblem $e) { check($e->httpStatus===409,'no confirmation for an unconfirmed payment'); }
    $csv=panelCsv([array_replace($row,['name'=>'=HYPERLINK("x")','note'=>'a"b'])]);
    check(str_starts_with($csv,"\u{FEFF}\"Numer\";") && str_contains($csv,"\"'=HYPERLINK(\"\"x\"\")\"") && str_contains($csv,'"a""b"'),'CSV opens in Excel and neutralises formulas');
    echo 'TOTAL '.$passed.PHP_EOL;
} finally {
    // Only this test-owned fixture; no real configuration or customer data.
    foreach (glob($private.'/*') as $file) unlink($file);
    unlink($private.'/.env');rmdir($private);
}
