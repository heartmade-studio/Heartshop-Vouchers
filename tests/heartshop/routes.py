#!/usr/bin/env python3
"""Real PHP routes on loopback, synthetic SQLite and keys; never call providers/mail."""
import base64
import hashlib
import hmac
import json
import os
from pathlib import Path
import socket
import sqlite3
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
    envfile.write_text('PAYMOVE_API_KEY=synthetic-only\nPAYMOVE_PRODUCT_ID=fixture\nPAYMOVE_WEBHOOK_SECRET=synthetic-signing-only\nPAYMOVE_TRANSACTION_SECRET=synthetic-transaction\nMAIL_FROM=shop@example.invalid\nMERCHANT_EMAIL=merchant@example.invalid\nSHOP_ORIGIN=https://staging.shop.example.com\nFURGONETKA_TOKEN='+'f'*32+'\n'+panel_hash+'\n')
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
    # Substitute only the provider status lookup in the PHP route. The web
    # server also disables outbound cURL so a broken fixture cannot call PAYMOVE.
    provider_state = private/'provider.json'
    provider_reads = private/'provider-reads'
    provider_state.write_text(json.dumps({'status': 'CANCELED', 'orderId': 'fixture-failed'}))
    prepend = private/'provider.php'
    prepend.write_text("""<?php
$statusLookup=function(string $url): array {
    file_put_contents(__DIR__.'/provider-reads',$url.PHP_EOL,FILE_APPEND);
    return json_decode(file_get_contents(__DIR__.'/provider.json'),true,flags:JSON_THROW_ON_ERROR);
};
""")
    def next_observation(order_id, status):
        provider_state.write_text(json.dumps({'status': status, 'orderId': 'fixture-voucher' if order_id == seeded['voucher']['orderId'] else 'fixture-pm'}))
        with sqlite3.connect(private/'orders.sqlite') as db:
            db.execute('UPDATE orders SET status_check_after=0 WHERE external_id=?', (order_id,))

    def check():
        return subprocess.run(['php', str(ROOT/'scripts/heartshop/retry-mail.php'), '--check',
                               str(ROOT/'public/heartshop/api/lib.php')], env=env, capture_output=True, text=True)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    with (private/'php.log').open('w') as log:
        proc = subprocess.Popen(['php', '-d', 'sendmail_path=/usr/bin/false', '-d', 'disable_functions=curl_exec',
                                 '-d', f'auto_prepend_file={prepend}', '-S', f'127.0.0.1:{port}', '-t', str(ROOT/'public')], env=env, stdout=log, stderr=log)
        def request(path, data=None, headers=None):
            req = urllib.request.Request(f'http://127.0.0.1:{port}/heartshop/api/{path}', data=data, headers=headers or {})
            try:
                response = urllib.request.urlopen(req, timeout=5)
            except urllib.error.HTTPError as e:
                response = e
            with response:
                return response.status, json.loads(response.read()), response.headers
        def event(status, price, order=None, provider='fixture-pm', secret=b'synthetic-signing-only', **fields):
            raw = json.dumps({'externalId': seeded['result']['orderId'] if order is None else order, 'status': status, 'price': price, 'orderId': provider, **fields}).encode()
            stamp = str(int(time.time()))
            signature = base64.b64encode(hmac.new(secret, stamp.encode()+b'.'+raw, hashlib.sha256).digest()).decode()
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
            next_observation(seeded['result']['orderId'], 'PENDING')
            canceled = request('status.php?o='+seeded['result']['orderId'])[1]
            assert canceled['status'] == 'confirming' and canceled['resumeAvailable'] is False
            # A new signed provider transaction ID for the same failed purchase is valid.
            assert event('COMPLETED', seeded['result']['total'])[1]['status'] == 'paid'
            assert event('COMPLETED', seeded['result']['total'])[1]['changed'] is False
            assert event('CANCELED', 0)[1]['status'] == 'paid'
            assert request('order.php', payload, headers)[1]['status'] == 'paid'
            voucher = seeded['voucher']['orderId']
            assert request(f'status.php?o={voucher}')[1].get('vouchers') is None  # no codes before payment
            transaction = dict(secret=b'synthetic-transaction', provider='fixture-voucher', currency='PLN', amountMinor=str(seeded['voucher']['total']))
            for state in ['INITIALIZED', 'ERROR', 'CANCELED', 'PENDING', 'WAITING_FOR_EXTERNAL_ACTION', 'COMPLETED', 'REFUNDED']:
                next_observation(voucher, state)
                reads_before = len(provider_reads.read_text().splitlines())
                observed = request(f'status.php?o={voucher}')[1]
                confirming = state in ('PENDING', 'WAITING_FOR_EXTERNAL_ACTION', 'COMPLETED')
                assert observed['status'] == ('confirming' if confirming else 'pending'), state
                assert observed['resumeAvailable'] == (state in ('INITIALIZED', 'ERROR', 'CANCELED')), state
                assert 'vouchers' not in observed and 'buyerEmail' not in observed
                assert request(f'status.php?o={voucher}')[1] == observed
                reads = provider_reads.read_text().splitlines()
                assert len(reads) == reads_before + 1 and reads[-1] == seeded['voucher']['redirectUrl']
                if state == 'COMPLETED':
                    fresh = check()
                    assert fresh.returncode == 0 and fresh.stdout == ''
                    with sqlite3.connect(private/'orders.sqlite') as db:
                        db.execute('UPDATE orders SET created_at=unixepoch()-960 WHERE external_id=?', (voucher,))
                    # PAYMOVE reported completion, but no signed webhook reached this order.
                    missing = check()
                    assert missing.returncode == 1 and missing.stdout == f"{observed['number']} pending 16 min\n", missing
                    with sqlite3.connect(private/'orders.sqlite') as db:
                        assert db.execute('SELECT count(*) FROM signed_completions').fetchone()[0] == 0
                        assert db.execute('SELECT count(*) FROM vouchers').fetchone()[0] == 0
                        assert db.execute('SELECT count(*) FROM mail_outbox WHERE order_external_id=?', (voucher,)).fetchone()[0] == 0
                        assert db.execute('SELECT status FROM orders WHERE external_id=?', (voucher,)).fetchone()[0] == 'pending'
            # REFUNDED superseded COMPLETED. Only a new signed candidate can now alert.
            healthy = check()
            assert healthy.returncode == 0 and healthy.stdout == ''
            assert event('COMPLETED', 0, '', type='PAYMENT', **transaction)[1]['status'] == 'received'
            stuck = check()
            assert stuck.returncode == 1 and stuck.stdout == f"{observed['number']} pending 16 min\n", stuck
            with sqlite3.connect(private/'orders.sqlite') as db:
                assert db.execute('SELECT count(*) FROM vouchers').fetchone()[0] == 0
                receipt = db.execute('SELECT received_at FROM signed_completions WHERE provider_id=?', ('fixture-voucher',)).fetchone()[0]
                db.execute('UPDATE signed_completions SET received_at=? WHERE provider_id=?', (receipt-86401, 'fixture-voucher'))
            expired = check()
            assert expired.returncode == 0 and expired.stdout == ''
            with sqlite3.connect(private/'orders.sqlite') as db:
                db.execute('UPDATE signed_completions SET received_at=? WHERE provider_id=?', (receipt, 'fixture-voucher'))
            # A refund before any confirmed payment withdraws nothing.
            assert event('COMPLETED', 0, voucher, type='REFUND', parentOrderId='fixture-other-attempt', **transaction)[1] == {'status': 'pending', 'changed': False}
            assert event('COMPLETED', 0, voucher, type='REFUND', **transaction)[0] == 400  # no parentOrderId
            assert event('COMPLETED', 0, voucher, type='CHARGEBACK', **transaction)[0] == 400
            assert event('COMPLETED', 0, voucher, type='PAYMENT', **dict(transaction, currency='EUR'))[0] == 400
            assert request(f'status.php?o={voucher}')[1].get('vouchers') is None
            next_observation(voucher, 'COMPLETED')
            assert request(f'status.php?o={voucher}')[1]['status'] == 'paid'
            assert event('COMPLETED', 0, voucher, type='PAYMENT', **transaction)[1]['status'] == 'paid'
            assert event('COMPLETED', seeded['voucher']['total'], voucher)[1]['changed'] is False
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
            # Refunds: only the signed refund of the paying transaction withdraws the order.
            refund = dict(transaction, provider='fixture-voucher-r1')
            assert event('COMPLETED', 0, voucher, type='REFUND', parentOrderId='fixture-duplicate', **refund)[1] == {'status': 'paid', 'changed': False}
            assert event('PENDING', 0, voucher, type='REFUND', parentOrderId='fixture-voucher', **refund)[1]['status'] == 'ignored'
            assert event('COMPLETED', 0, voucher, type='REFUND', parentOrderId='fixture-voucher', **refund)[1] == {'status': 'refunded', 'changed': True}
            assert event('COMPLETED', 0, '', type='REFUND', parentOrderId='fixture-voucher', **refund)[1] == {'status': 'ignored', 'changed': False}
            assert event('COMPLETED', 0, voucher, type='REFUND', parentOrderId='fixture-voucher', **refund)[1] == {'status': 'refunded', 'changed': False}
            refunded = request(f'status.php?o={voucher}')[1]
            assert refunded['status'] == 'refunded' and 'vouchers' not in refunded
            for action in ('redeem', 'unredeem'):
                code, body, _ = request(f'panel.php?action={action}', redeem, session)
                assert code == 409 and body['code'] == 'refunded', body
            assert event('COMPLETED', seeded['voucher']['total'], voucher)[1] == {'status': 'refunded', 'changed': False}
            with sqlite3.connect(private/'orders.sqlite') as db:
                subject, body = db.execute('SELECT subject, body FROM mail_outbox WHERE order_external_id=? ORDER BY id DESC LIMIT 1', (voucher,)).fetchone()
                assert subject.startswith('Zwrot płatności') and voucher_code+' (był już wykorzystany)' in body and 'fixture-voucher-r1' in body
            with urllib.request.urlopen(urllib.request.Request(f'http://127.0.0.1:{port}/heartshop/api/panel.php?action=export', headers=session), timeout=5) as csv:
                text = csv.read().decode('utf-8-sig')
                assert csv.headers['Content-Type'].startswith('text/csv') and voucher_code in text and 'buyer@example.invalid' in text
            assert request('panel.php?action=logout', b'{}', session)[0] == 200
            assert request('panel.php?action=orders', headers=session)[0] == 401
            fresh = check()
            assert fresh.returncode == 0 and fresh.stdout == '', fresh
            subprocess.run(['php', '-r', '$db=new SQLite3($argv[1]);$db->exec("UPDATE mail_outbox SET created_at=created_at-1000");', str(private/'orders.sqlite')], check=True)
            stale = check()
            assert stale.returncode == 1 and 'Heartshop mail alert for https://staging.shop.example.com: 5 unsent' in stale.stdout, stale
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
