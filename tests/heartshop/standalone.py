#!/usr/bin/env python3
"""Distribution smoke tests: actual defaults, isolated checkout and production gates."""
import json, os, shutil, socket, sqlite3, subprocess, tempfile, time, urllib.request, urllib.error
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
KIND=(ROOT/'config/distribution').read_text().strip()
with tempfile.TemporaryDirectory(prefix='heartshop-standalone-') as temp:
    base=Path(temp);repo=base/'repo'
    shutil.copytree(ROOT,repo,ignore=shutil.ignore_patterns('.git','var','private','__pycache__'))
    with socket.socket() as sock:
        sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    origin=f'http://localhost:{port}'
    log=(base/'server.log').open('w')
    proc=subprocess.Popen(['php','scripts/dev/start.php',str(port)],cwd=repo,stdout=log,stderr=log,start_new_session=True)
    def request(path,data=None,headers=None):
        hdr=dict(headers or {})
        if data is not None:
            data=json.dumps(data).encode();hdr.setdefault('Content-Type','application/json');hdr.setdefault('Origin',origin)
        req=urllib.request.Request(origin+path,data=data,headers=hdr)
        try:r=urllib.request.urlopen(req,timeout=5)
        except urllib.error.HTTPError as e:r=e
        with r:
            raw=r.read()
            return r.status, json.loads(raw) if 'application/json' in r.headers.get('Content-Type','') else raw,r.headers
    try:
        for _ in range(100):
            try:
                if request('/heartshop/')[0]==200:break
            except OSError:pass
            time.sleep(.05)
        else:raise AssertionError('Server failed: '+(base/'server.log').read_text())
        code,cfg,_=request('/heartshop/api/order.php');assert code==200
        assert len(cfg['offers'])==1 and cfg['sandbox'] is True
        typ='shipping' if KIND=='goods' else 'voucher'
        assert cfg['product']['type']==typ
        if KIND=='goods':assert cfg['invoice'] is True and cfg['delivery']['kurier']
        else:
            assert cfg['invoice'] is False and not cfg['delivery']
            assert not (repo/'public/heartshop/api/furgonetka.php').exists()
            assert request('/heartshop/api/furgonetka/orders')[0] in [403,404]
        for path in ['/heartshop/api/config.php','/scripts/dev/router.php','/var/local/.env','/heartshop/.htaccess']:
            assert request(path)[0] in [403,404],path
        assert request('/heartshop/',headers={'Host':'attacker.invalid'})[0]==403
        data={'qty':1,'name':'Test Buyer','email':'buyer@example.invalid','terms':True,'invoice':False}
        if KIND=='goods':data.update(phone='500600700',delivery='kurier',street='Test 1',postcode='00-001',city='Test')
        key={'Idempotency-Key':'a'*32}
        assert request('/heartshop/api/order.php',data,dict(key,Origin='http://attacker.invalid'))[0]==403
        wrong=dict(data,offer='masaz-60' if KIND=='goods' else 'magic-box')
        assert request('/heartshop/api/order.php',wrong,{'Idempotency-Key':'b'*32})[0]==422
        code,order,_=request('/heartshop/api/order.php',data,key);assert code==200,order
        assert request('/heartshop/api/order.php',data,key)[1]['orderId']==order['orderId']
        assert request('/__dev/settle',{'orderId':order['orderId']}, {'Origin':'http://attacker.invalid'})[0]==403
        assert request('/__dev/settle',{'orderId':order['orderId']})[1]['status']=='paid'
        status=request('/heartshop/api/status.php?o='+order['orderId'])[1]
        assert status['status']=='paid' and status['demo'] is True and status['type']==typ
        if KIND=='vouchers':
            assert len(status['vouchers'])==1
            with sqlite3.connect(repo/'var/local/orders.sqlite') as db:
                snap=json.loads(db.execute('SELECT data FROM orders WHERE external_id=?',(order['orderId'],)).fetchone()[0])['snapshot']
                body=db.execute('SELECT body FROM mail_outbox WHERE recipient=?',('buyer@example.invalid',)).fetchone()[0]
                assert snap['invoice_request_text'] in body and 'Faktura na żądanie' in body
            cfgfile=repo/'public/heartshop/api/config.php'
            cfgfile.write_text(cfgfile.read_text().replace('Faktura na żądanie:', 'ZMIENIONA instrukcja:'))

            assert request('/heartshop/twoj-voucher.html?o='+order['orderId'])[0]==200
        else:
            assert request('/heartshop/api/furgonetka/orders',headers={'Authorization':'f'*32})[1]==[]
        code,_,h=request('/heartshop/api/panel.php?action=login',{'password':'local-heartshop-only'});assert code==200
        cookie=h['Set-Cookie'].split(';')[0]
        code,orders,_=request('/heartshop/api/panel.php?action=orders',headers={'Cookie':cookie});assert code==200 and len(orders['orders'])==1
        if KIND=='vouchers':
            code,_,_=request('/heartshop/api/panel.php?action=redeem',{'code':status['vouchers'][0]['code']},{'Cookie':cookie});assert code==200

        if KIND=='vouchers':
            assert request('/heartshop/api/panel.php?action=resend',{'id':order['orderId']},{'Cookie':cookie})[0]==200
            with sqlite3.connect(repo/'var/local/orders.sqlite') as db:
                bodies=[r[0] for r in db.execute('SELECT body FROM mail_outbox WHERE recipient=?',('buyer@example.invalid',))]
                assert all('Faktura na żądanie:' in body and 'ZMIENIONA instrukcja:' not in body for body in bodies)
            print('PASS invoice request: checkout snapshot, queued buyer email and resend preserve instructions')
        assert request('/__dev/paymove.js')[0]==200
        print('PASS actual '+KIND+' distribution: start, boundaries, order, idempotency, simulation, panel and fulfillment isolation')
    finally:
        import signal
        os.killpg(proc.pid,signal.SIGTERM);proc.wait(timeout=5);log.close()

    # Production mode: use only a stub provider, never the network. Copy and edit the
    # actual deployment config to test BOTH production_ready and per-offer demo gates.
    runtime=repo/'public/heartshop/api'
    env=repo/'var/local/.env';env.write_text(env.read_text().replace('PAYMOVE_ENV=sandbox','PAYMOVE_ENV=production').replace(origin,'https://shop.example.com'));env.chmod(0o600)
    runner=base/'production.php'
    runner.write_text('''<?php
$_SERVER['DOCUMENT_ROOT']=$argv[1].'/public';require $argv[1].'/public/heartshop/api/lib.php';
$input=['qty'=>1,'name'=>'Test Buyer','email'=>'buyer@example.invalid','terms'=>true,'invoice'=>false,'phone'=>'500600700','delivery'=>'kurier','street'=>'Test 1','postcode'=>'00-001','city'=>'Test'];
$calls=0;
try { $result=prepareCheckout($input,str_repeat($argv[2],32),function($id)use(&$calls){$calls++;return 'https://checkout.paymove.io/fixture/'.$id;});echo json_encode(['calls'=>$calls,'order'=>findOrder($result['orderId'])]); }
catch(CheckoutProblem $e){echo json_encode(['calls'=>$calls,'error'=>$e->reason]);}
''')
    penv=dict(os.environ,HEARTSHOP_ENV_FILE=str(env));penv.pop('HEARTSHOP_ORIGIN',None)
    def production(key):return json.loads(subprocess.check_output(['php',str(runner),str(repo),key],env=penv))
    p=runtime/'config.php';original=p.read_text()
    a=production('c');assert a['error']=='demo_only' and a['calls']==0
    p.write_text(original.replace("'production_ready' => false","'production_ready' => true"))
    a=production('d');assert a['error']=='demo_only' and a['calls']==0
    p.write_text(original.replace("'demo'    => true","'demo'    => false").replace("'demo'         => true","'demo'         => false"))
    a=production('e');assert a['error']=='demo_only' and a['calls']==0
    p.write_text(p.read_text().replace("'production_ready' => false","'production_ready' => true"))
    if typ=='voucher':
        a=production('9');assert a['error']=='demo_only' and a['calls']==0  # sample invoice text still blocks
        p.write_text(p.read_text().replace('To przykładowa instrukcja do zatwierdzenia przez sprzedawcę.','Fakturę wystawimy ręcznie.'))
    a=production('f');assert a['calls']==1 and a['order']['data']['snapshot']['demo'] is False
    assert a['order']['offer_type']==typ
    print('PASS production gates: default denied, each independent gate denied, configured real offer accepted with stub provider')
