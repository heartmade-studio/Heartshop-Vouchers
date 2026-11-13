#!/usr/bin/env python3
"""Real PHP routes on loopback, synthetic SQLite and keys; never call providers/mail."""
import base64
import hashlib
import hmac
import json
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
scratch = None
with tempfile.TemporaryDirectory(prefix='heartshop-http-', dir=scratch) as tmp:
    private = Path(tmp)
    envfile = private/'.env'
    panel_hash = subprocess.check_output(['php', str(ROOT/'scripts/heartshop/panel-password.php')], input=b'synthetic-panel-pass\n').decode().strip()
    assert panel_hash.startswith('PANEL_PASSWORD_HASH=$')
    envfile.write_text('PAYMOVE_API_KEY=synthetic-only\nPAYMOVE_PRODUCT_ID=fixture\nPAYMOVE_WEBHOOK_SECRET=synthetic-signing-only\nMAIL_FROM=shop@example.invalid\nMERCHANT_EMAIL=merchant@example.invalid\nSHOP_ORIGIN=https://staging.shop.example.com\nFURGONETKA_TOKEN='+'f'*32+'\n'+panel_hash+'\n')
    envfile.chmod(0o600)
    env = dict(os.environ, HEARTSHOP_ENV_FILE=str(envfile))
    fixture = private/'seed.php'
    fixture.write_text('''<?php
$_SERVER['DOCUMENT_ROOT']=$argv[1];require $argv[1].'/public/heartshop/api/lib.php';
$input=['qty'=>1,'email'=>'buyer@example.invalid','phone'=>'500600700','name'=>'Test Buyer','delivery'=>'kurier','street'=>'Test 1','postcode'=>'00-001','city'=>'Test','invoice'=>false,'terms'=>true];
$result=prepareCheckout($input,str_repeat('a',32),fn($id)=>'https://checkout.sandbox.paymove.io/fixture/'.$id);
$vinput=['offer'=>'masaz-60','qty'=>1,'email'=>'giver@example.invalid','name'=>'Gift Giver','recipient'=>'Ania','dedication'=>'','invoice'=>false,'terms'=>true];
$voucher=prepareCheckout($vinput,str_repeat('e',32),fn($id)=>'https://checkout.sandbox.paymove.io/fixture/'.$id);
$db=db();$db->exec("UPDATE orders SET data=json_set(data, '$.snapshot.demo', json('false')) WHERE offer_type='shipping'");
echo json_encode(['input'=>$input,'result'=>$result,'voucher'=>$voucher]);
''')
    seeded = json.loads(subprocess.check_output(['php', str(fixture), str(ROOT)], env=env))
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    with (private/'php.log').open('w') as log:
        proc = subprocess.Popen(['php', '-d', 'sendmail_path=/usr/bin/false', '-S', f'127.0.0.1:{port}', '-t', str(ROOT/'public')], env=env, stdout=log, stderr=log)
        def request(path, data=None, headers=None):
            req = urllib.request.Request(f'http://127.0.0.1:{port}/heartshop/api/{path}', data=data, headers=headers or {})
            try:
                response = urllib.request.urlopen(req, timeout=5)
            except urllib.error.HTTPError as e:
                response = e
            with response:
                return response.status, json.loads(response.read()), response.headers
        def event(status, price, order=None, provider='fixture-pm'):
            raw = json.dumps({'externalId': order or seeded['result']['orderId'], 'status': status, 'price': price, 'orderId': provider}).encode()
            stamp = str(int(time.time()))
            signature = base64.b64encode(hmac.new(b'synthetic-signing-only', stamp.encode()+b'.'+raw, hashlib.sha256).digest()).decode()
            return request('webhook.php', raw, {'X-Paymove-Signature': f't={stamp},v1={signature}'})
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=0.1):
                        break
                except OSError:
                    if proc.poll() is not None:
                        raise RuntimeError('PHP fixture exited')
                    time.sleep(0.03)
            headers = {'Origin': 'https://staging.shop.example.com', 'Idempotency-Key': 'a'*32, 'Content-Type': 'application/json'}
            payload = json.dumps(seeded['input']).encode()
            code, body, hdr = request('order.php', payload, headers)
            # The synthetic URL deliberately has no real Paymove payment hash.
            # A resumed uncheckable payment is held, never replaced by a new order.
            assert code == 409 and body['code'] == 'payment_review' and body['orderId'] == seeded['result']['orderId']
            assert hdr['Cache-Control'] == 'no-store' and hdr.get('Retry-After') is None
            assert request('order.php', payload)[0] == 403
            changed = dict(seeded['input'], qty=2)
            assert request('order.php', json.dumps(changed).encode(), headers)[0] == 409
            invalid = dict(seeded['input'], email='bad')
            assert request('order.php', json.dumps(invalid).encode(), dict(headers, **{'Idempotency-Key': 'b'*32}))[0] == 422
            assert request('webhook.php', b'{}')[0] == 401
            assert event('ERROR', 0, provider='fixture-failed')[1]['status'] == 'canceled'
            assert request('status.php?o='+seeded['result']['orderId'])[1]['status'] == 'canceled'
            # Model the unsigned PENDING observation from resuming that same purchase.
            hint = private/'hint.php'
            hint.write_text('''<?php
$_SERVER['DOCUMENT_ROOT']=$argv[1];require $argv[1].'/public/heartshop/api/lib.php';
$s=db()->prepare('UPDATE orders SET data=json_set(data,"$.payment_observation",json(:hint)) WHERE external_id=:id');
$s->bindValue(':hint',json_encode(['status'=>'PENDING','order_id'=>'fixture-pm']));$s->bindValue(':id',$argv[2]);$s->execute();
''')
            subprocess.check_call(['php',str(hint),str(ROOT),seeded['result']['orderId']],env=env)
            assert request('status.php?o='+seeded['result']['orderId'])[1]['status'] == 'pending'
            # A new signed provider transaction ID for the same failed purchase is valid.
            assert event('COMPLETED', seeded['result']['total'])[1]['status'] == 'paid'
            assert event('COMPLETED', seeded['result']['total'])[1]['changed'] is False
            assert event('CANCELED', 0)[1]['status'] == 'paid'
            assert request('order.php', payload, headers)[1]['status'] == 'paid'
            voucher = seeded['voucher']['orderId']
            assert request(f'status.php?o={voucher}')[1].get('vouchers') is None  # no codes before payment
            assert event('COMPLETED', seeded['voucher']['total'], voucher)[1]['status'] == 'paid'
            code, status, _ = request(f'status.php?o={voucher}')
            assert code == 200 and status['type'] == 'voucher' and status['recipient'] == 'Ania' and len(status['vouchers']) == 1
            assert status['buyerEmail'] == 'giver@example.invalid' and status['vouchers'][0]['code']  # booking by email; the number tells copies apart
            queue = json.loads(subprocess.check_output(['php', str(ROOT/'scripts/heartshop/retry-mail.php'), '--status', str(ROOT/'public/heartshop/api/lib.php')], env=env))
            assert queue['queue'] == [{'status': 'pending', 'count': 4, 'max_attempts': 0}]

            # Panel over HTTP: session cookie, same-origin POSTs only.
            origin = {'Origin': 'https://staging.shop.example.com', 'Content-Type': 'application/json'}
            assert request('panel.php?action=session')[1]['authenticated'] is False
            assert request('panel.php?action=orders')[0] == 401
            assert request('panel.php?action=login', json.dumps({'password': 'wrong'}).encode(), origin)[0] == 401
            assert request('panel.php?action=login', json.dumps({'password': 'synthetic-panel-pass'}).encode())[0] == 403
            code, _, hdr = request('panel.php?action=login', json.dumps({'password': 'synthetic-panel-pass'}).encode(), origin)
            cookie = hdr['Set-Cookie']
            flags = [part.strip().lower() for part in cookie.split(';')]
            assert code == 200 and {'httponly', 'secure', 'samesite=strict', 'path=/heartshop/api/'} <= set(flags), cookie
            session = dict(origin, Cookie=cookie.split(';')[0])
            code, listed, _ = request('panel.php?action=orders', headers=session)
            assert code == 200 and {o['type'] for o in listed['orders']} == {'shipping', 'voucher'}
            lookup = json.dumps({'email':'GIVER@example.invalid'}).encode()
            assert request('panel.php?action=voucherLookup', lookup, origin)[0] == 401
            found = request('panel.php?action=voucherLookup', lookup, session)[1]['orders']
            assert len(found) == 1 and found[0]['id'] == voucher
            voucher_code = found[0]['vouchers'][0]['code']
            redeem = json.dumps({'code': voucher_code.lower()}).encode()
            assert request('panel.php?action=redeem', redeem, {'Cookie': session['Cookie'], 'Content-Type': 'application/json'})[0] == 403
            code, redeemed, _ = request('panel.php?action=redeem', redeem, session)
            assert code == 200 and redeemed['voucher']['status'] == 'used'
            code, again, _ = request('panel.php?action=redeem', redeem, session)
            assert code == 409 and again['code'] == 'already_used'
            assert request(f'status.php?o={voucher}')[1]['vouchers'][0]['status'] == 'used'
            with urllib.request.urlopen(urllib.request.Request(f'http://127.0.0.1:{port}/heartshop/api/panel.php?action=export', headers=session), timeout=5) as csv:
                text = csv.read().decode('utf-8-sig')
                assert csv.headers['Content-Type'].startswith('text/csv') and voucher_code in text and 'buyer@example.invalid' in text
            assert request('panel.php?action=logout', b'{}', session)[0] == 200
            assert request('panel.php?action=orders', headers=session)[0] == 401
            check = lambda: subprocess.run(['php', str(ROOT/'scripts/heartshop/retry-mail.php'), '--check', str(ROOT/'public/heartshop/api/lib.php')], env=env, capture_output=True, text=True)
            fresh = check()
            assert fresh.returncode == 0 and fresh.stdout == '', fresh
            subprocess.run(['php', '-r', '$db=new SQLite3($argv[1]);$db->exec("UPDATE mail_outbox SET created_at=created_at-1000");', str(private/'orders.sqlite')], check=True)
            stale = check()
            assert stale.returncode == 1 and 'Heartshop mail alert for https://staging.shop.example.com: 4 unsent' in stale.stdout, stale
            assert 'buyer@example.invalid' not in stale.stdout and 'Test Buyer' not in stale.stdout
            (private/'mail-worker.err').write_text('Worker failed; inspect protected operator logs\n')
            subprocess.run(['php', '-r', '$db=new SQLite3($argv[1]);$db->exec("UPDATE mail_outbox SET status=\'sent\'");', str(private/'orders.sqlite')], check=True)
            errored = check()
            assert errored.returncode == 1 and 'worker errors in the last hour' in errored.stdout, errored
            # The example physical product must never reach the shipping integration.
            box_fixture = private/'box.php'
            box_fixture.write_text("""<?php
$_SERVER['DOCUMENT_ROOT']=$argv[1];require $argv[1].'/public/heartshop/api/lib.php';
$input=['offer'=>'magic-box','qty'=>1,'email'=>'box@example.invalid','phone'=>'500600700','name'=>'Test Buyer','delivery'=>'kurier','street'=>'Test 1','postcode'=>'00-001','city'=>'Test','invoice'=>false,'terms'=>true];
$r=prepareCheckout($input,str_repeat('f',32),fn($id)=>'https://checkout.sandbox.paymove.io/fixture/'.$id);
applyPaymentEvent(['externalId'=>$r['orderId'],'status'=>'COMPLETED','price'=>(string)$r['total'],'orderId'=>'fixture-box']);
echo json_encode($r);
""")
            box = json.loads(subprocess.check_output(['php', str(box_fixture), str(ROOT)], env=env))
            assert request('status.php?o='+box['orderId'])[1]['offer'] == 'magic-box'
            assert request('status.php?o='+seeded['result']['orderId'])[1]['offer'] == '1'
            print('PASS example box identified correctly status preserved in core fixture')
            prod = private/'prod'
            prod.mkdir(mode=0o700)
            prod_env = prod/'.env'
            prod_env.write_text(envfile.read_text()+'PAYMOVE_ENV=production\n')
            prod_env.chmod(0o600)
            prod_vars = dict(env, HEARTSHOP_ENV_FILE=str(prod_env))
            guard_fixture = private/'guard.php'
            guard_fixture.write_text("""<?php
$_SERVER['DOCUMENT_ROOT']=$argv[1];require $argv[1].'/public/heartshop/api/lib.php';
$common=['qty'=>1,'email'=>'test@example.invalid','name'=>'Test Buyer','invoice'=>false,'terms'=>true];
$box=$common+['offer'=>'magic-box','phone'=>'500600700','delivery'=>'kurier','street'=>'Test 1','postcode'=>'00-001','city'=>'Test'];
$voucher=$common+['offer'=>'masaz-60','recipient'=>'Ani'];
foreach ([$box,$voucher] as $i=>$input) {
 try { prepareCheckout($input,str_repeat((string)($i+1),32),fn()=>throw new Exception('provider called')); exit(1); }
 catch (CheckoutProblem $e) { if ($e->reason!=='demo_only') throw $e; }
}
""")
            subprocess.run(['php', str(guard_fixture), str(ROOT)], env=prod_vars, check=True)
            print('PASS demo offers reject new production payments before contacting provider')

            print('PASS PHP HTTP: idempotent retry/conflict/validation/origin/auth; signed webhook/duplicate/cancel; voucher codes after payment; panel login/cookie/origin/redeem/CSV/logout; durable unsent queue, CLI status and stale-queue alert')
        finally:
            proc.terminate()
            proc.wait(timeout=10)
