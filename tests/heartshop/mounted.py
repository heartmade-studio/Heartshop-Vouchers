#!/usr/bin/env python3
"""Shop mounted at /vouchers inside another website, seller panel on its own host.
Synthetic config, keys and SQLite only; the payment provider is stubbed and mail is disabled.
"""
import base64, hashlib, hmac, json, os, re, shutil, socket, sqlite3, subprocess, tempfile, time
import urllib.error, urllib.request
from pathlib import Path

import apache

ROOT = Path(__file__).resolve().parents[2]
SHOP, PANEL = 'https://shop.example.com', 'https://panel.example.com'


def run(cmd, cwd, ok=True, env=None):
    p = subprocess.run(cmd, cwd=cwd, text=True, capture_output=True, env=env)
    assert (p.returncode == 0) == ok, (cmd, p.stdout, p.stderr)
    return p.stdout + p.stderr


def free_port():
    with socket.socket() as s:
        s.bind(('127.0.0.1', 0))
        return s.getsockname()[1]


with tempfile.TemporaryDirectory(prefix='heartshop-mounted-') as temp:
    tmp = Path(temp)
    repo = tmp/'repo'
    shutil.copytree(ROOT, repo, ignore=shutil.ignore_patterns('.git', 'var', 'private', '__pycache__'))
    private = tmp/'private'
    private.mkdir(mode=0o700)
    config = repo/'public/heartshop/api/config.php'
    original = config.read_text()
    roots = {f: (repo/'public'/f).read_bytes() for f in ['.htaccess', 'robots.txt', 'sitemap.xml']}

    # Invalid layouts fail closed before anything is built.
    for bad in ["'/vouchers/'", "'/Vouchers'", "'/a/b'", "''"]:
        config.write_text(original.replace("'base'   => '/heartshop'", f"'base'   => {bad}"))
        run(['php', 'scripts/heartshop/build-storefront.php'], repo, ok=False)
    for bad in ["['http://panel.example.com']", "['https://*.example.com']", "['https://shop.example.com']", "'https://panel.example.com'"]:
        config.write_text(original.replace("'panel_origins' => []", f"'panel_origins' => {bad}"))
        run(['php', 'scripts/heartshop/build-storefront.php'], repo, ok=False)

    mounted = (original.replace("'base'   => '/heartshop'", "'base'   => '/vouchers'")
               .replace("'mount'  => 'root'", "'mount'  => 'subdirectory'")
               .replace("'panel_origins' => []", f"'panel_origins' => ['{PANEL}']"))
    config.write_text(mounted)
    run(['php', 'scripts/heartshop/build-storefront.php'], repo)
    run(['php', 'scripts/heartshop/build-storefront.php', '--check'], repo)
    assert all((repo/'public'/f).read_bytes() == b for f, b in roots.items()), 'mounted build touched web-root files'
    htaccess = (repo/'public/heartshop/.htaccess').read_text()
    assert '(?:order|status|webhook)\\.php$' in htaccess and 'panel' not in htaccess.split('ENTRY POINTS')[1]

    # One .env and one database for both trees: a relative env_file is refused.
    run(['php', 'scripts/heartshop/release.php', str(tmp/'refused')], repo, ok=False)
    assert not (tmp/'refused').exists()
    envfile = private/'.env'
    mounted = mounted.replace("dirname(__DIR__, 3) . '/private/heartshop/.env'", repr(str(envfile)))
    config.write_text(mounted)
    run(['php', 'scripts/heartshop/build-storefront.php', '--check'], repo)
    out = tmp/'release'
    print(run(['php', 'scripts/heartshop/release.php', str(out)], repo).strip().splitlines()[-2])
    run(['php', 'scripts/heartshop/release.php', str(out)], repo, ok=False)  # never overwrites a release

    files = {t: sorted(str(p.relative_to(out/t)) for p in (out/t).rglob('*') if p.is_file()) for t in ['shop', 'panel']}
    assert not (out/'root').exists()
    assert 'api/panel.php' not in files['shop'] and not any(f.startswith('panel/') for f in files['shop'])
    assert {'api/order.php', 'api/status.php', 'api/webhook.php', 'index.html'} <= set(files['shop'])
    assert not {'api/order.php', 'api/status.php', 'api/webhook.php', 'index.html', 'zamowienie.html'} & set(files['panel'])
    assert {'.htaccess', 'panel/index.html', 'api/panel.php', 'api/lib.php', 'assets/panel.js'} <= set(files['panel'])
    assert (out/'shop/api/release-id.php').read_bytes() == (out/'panel/api/release-id.php').read_bytes()
    assert not any(re.search(r'(^|/)(\.env|private|scripts|tests)(/|$)', f) for t in files.values() for f in t)
    panel_rules = (out/'panel/.htaccess').read_text()
    assert 'panel\\.example\\.com' in panel_rules and 'shop\\.example\\.com' not in panel_rules
    assert '(?:panel)\\.php$' in panel_rules

    # ---------- real PHP routes: two document roots, one private database ----------
    panel_hash = subprocess.check_output(['php', str(ROOT/'scripts/heartshop/panel-password.php')], input=b'synthetic-panel-pass\n').decode().strip()
    secrets = ('PAYMOVE_API_KEY=synthetic-only\nPAYMOVE_PRODUCT_ID=fixture\nPAYMOVE_WEBHOOK_SECRET=synthetic-signing-only\n'
               f'MAIL_FROM=shop@example.invalid\nMERCHANT_EMAIL=merchant@example.invalid\nSHOP_ORIGIN={SHOP}\n{panel_hash}\n')
    envfile.write_text(secrets + f'PANEL_ORIGIN={PANEL}\n')
    envfile.chmod(0o600)
    site = tmp/'site'
    (site/'index.html').parent.mkdir()
    (site/'index.html').write_text('host website')
    shutil.copytree(out/'shop', site/'vouchers')
    clean_env = {k: v for k, v in os.environ.items() if not k.startswith('HEARTSHOP_')}

    seed = tmp/'seed.php'
    seed.write_text('''<?php
$_SERVER['DOCUMENT_ROOT']=$argv[1];require $argv[1].'/vouchers/api/lib.php';
$in=['offer'=>'masaz-60','qty'=>1,'email'=>'giver@example.invalid','name'=>'Gift Giver','recipient'=>'Ania','dedication'=>'','invoice'=>false,'terms'=>true];
echo json_encode(prepareCheckout($in,str_repeat('e',32),fn($id)=>'https://checkout.sandbox.paymove.io/fixture/'.$id));
''')
    seeded = json.loads(subprocess.check_output(['php', str(seed), str(site)], env=clean_env))

    servers = []
    def serve(docroot):
        port = free_port()
        log = (tmp/f'php-{port}.log').open('w')
        servers.append((subprocess.Popen(['php', '-d', 'sendmail_path=/usr/bin/false', '-S', f'127.0.0.1:{port}', '-t', str(docroot)],
                                         env=clean_env, stdout=log, stderr=log), log))
        for _ in range(100):
            try:
                with socket.create_connection(('127.0.0.1', port), timeout=.1):
                    return f'http://127.0.0.1:{port}'
            except OSError:
                time.sleep(.03)
        raise RuntimeError('PHP server did not start')

    def request(url, data=None, headers=None):
        req = urllib.request.Request(url, data=data, headers=headers or {})
        try:
            r = urllib.request.urlopen(req, timeout=5)
        except urllib.error.HTTPError as e:
            r = e
        with r:
            raw = r.read()
            body = json.loads(raw) if 'json' in r.headers.get('Content-Type', '') else raw
            return r.status, body, r.headers

    try:
        shop, panel = serve(site), serve(out/'panel')
        assert request(shop+'/vouchers/api/order.php')[0] == 200
        # php -S falls back to a parent index.html for missing directories; Apache below checks /vouchers/panel/.
        for path in ['/vouchers/api/panel.php?action=session']:
            assert request(shop+path)[0] == 404, path
        for path in ['/api/order.php', '/api/status.php?o=x', '/api/webhook.php', '/index.html', '/zamowienie.html']:
            assert request(panel+path)[0] == 404, path

        raw = json.dumps({'externalId': seeded['orderId'], 'status': 'COMPLETED', 'price': seeded['total'], 'orderId': 'fixture-pm'}).encode()
        stamp = str(int(time.time()))
        sig = base64.b64encode(hmac.new(b'synthetic-signing-only', stamp.encode()+b'.'+raw, hashlib.sha256).digest()).decode()
        code, paid, _ = request(shop+'/vouchers/api/webhook.php', raw, {'X-Paymove-Signature': f't={stamp},v1={sig}'})
        assert code == 200 and paid['status'] == 'paid', paid
        with sqlite3.connect(private/'orders.sqlite') as db:
            body = db.execute('SELECT body FROM mail_outbox WHERE recipient=?', ('giver@example.invalid',)).fetchone()[0]
        assert f'{SHOP}/vouchers/twoj-voucher.html?o={seeded["orderId"]}' in body and '/heartshop/' not in body

        login = json.dumps({'password': 'synthetic-panel-pass'}).encode()
        jsonh = {'Content-Type': 'application/json'}
        assert request(panel+'/api/panel.php?action=login', login, dict(jsonh, Origin=SHOP))[0] == 403
        assert request(panel+'/api/panel.php?action=login', login, jsonh)[0] == 403
        code, _, hdr = request(panel+'/api/panel.php?action=login', login, dict(jsonh, Origin=PANEL))
        cookie = hdr['Set-Cookie']
        flags = {p.strip().lower() for p in cookie.split(';')}
        assert code == 200 and {'httponly', 'secure', 'samesite=strict', 'path=/api/'} <= flags, cookie
        assert not any(f.startswith('domain=') for f in flags), cookie
        session = dict(jsonh, Origin=PANEL, Cookie=cookie.split(';')[0])
        code, listed, _ = request(panel+'/api/panel.php?action=orders', headers=session)
        assert code == 200 and [o['status'] for o in listed['orders']] == ['paid']
        voucher = listed['orders'][0]['vouchers'][0]['code']
        assert request(panel+'/api/panel.php?action=redeem', json.dumps({'code': voucher}).encode(), dict(session, Origin=SHOP))[0] == 403
        assert request(panel+'/api/panel.php?action=redeem', json.dumps({'code': voucher}).encode(), session)[0] == 200
        code, csv, hdr = request(panel+'/api/panel.php?action=export', headers=session)
        assert code == 200 and hdr['Content-Type'].startswith('text/csv') and voucher in csv.decode('utf-8-sig')
        # Host/forwarding headers never choose the origin.
        spoof = dict(jsonh, Origin=SHOP, Host='panel.example.com', **{'X-Forwarded-Host': 'panel.example.com', 'X-Forwarded-Proto': 'https'})
        assert request(panel+'/api/panel.php?action=login', login, spoof)[0] == 403
        # The panel origin cannot place shop orders.
        order = json.dumps({'offer': 'masaz-60', 'qty': 1, 'email': 'x@example.invalid', 'name': 'Test Buyer', 'terms': True}).encode()
        assert request(shop+'/vouchers/api/order.php', order, dict(jsonh, Origin=PANEL, **{'Idempotency-Key': 'b'*32}))[0] == 403

        # Misconfigured PANEL_ORIGIN fails closed.
        for bad in ['', 'PANEL_ORIGIN=https://other.example.com\n', f'PANEL_ORIGIN={SHOP}\n']:
            envfile.write_text(secrets + bad)
            assert request(panel+'/api/panel.php?action=session')[0] == 503, bad
        envfile.write_text(secrets + f'PANEL_ORIGIN={PANEL}\n')
    finally:
        for proc, log in servers:
            proc.terminate(); proc.wait(timeout=10); log.close()

    # Without panel_origins, a stray PANEL_ORIGIN is refused too.
    default_env = tmp/'default'/'.env'
    default_env.parent.mkdir(mode=0o700)
    default_env.write_text(secrets.replace(SHOP, 'https://shop.example.com') + f'PANEL_ORIGIN={PANEL}\n')
    default_env.chmod(0o600)
    probe = 'require $argv[1]."/public/heartshop/api/lib.php"; try { secrets(); echo "accepted"; } catch (RuntimeException $e) { echo $e->getMessage(); }'
    assert subprocess.check_output(['php', '-r', probe, str(ROOT)], env=dict(clean_env, HEARTSHOP_ENV_FILE=str(default_env)), text=True) == 'Invalid panel origin'

    # ---------- real Apache: shop under /vouchers on the website host, panel on its own vhost ----------
    def layout(base, http, https):
        web = base/'www'
        shutil.copytree(site, web)
        (web/'.htaccess').write_text('# host website root rules are not the shop\'s\n')
        panel_root = base/'panel'
        shutil.copytree(out/'panel', panel_root)
        return web, f'''
<VirtualHost 127.0.0.1:{http}>
  DocumentRoot "{web}"
</VirtualHost>
<VirtualHost 127.0.0.1:{http}>
  ServerName panel.example.com
  DocumentRoot "{panel_root}"
</VirtualHost>
<VirtualHost 127.0.0.1:{https}>
  ServerName panel.example.com
  DocumentRoot "{panel_root}"
  SSLEngine on
  SSLProtocol -all +TLSv1.2
  SSLCertificateFile "@CERT@"
  SSLCertificateKeyFile "@KEY@"
</VirtualHost>
<Directory "{panel_root}">
  Options FollowSymLinks
  AllowOverride All
  Require all granted
</Directory>
'''

    def exercise(get, http, https, web):
        def status(url, host, headers=None):
            with get(url, dict(headers or {}, Host=host)) as r:
                return r.status, r.headers, r.read()
        S, H = f'https://127.0.0.1:{https}', f'http://127.0.0.1:{http}'
        code, hdr, _ = status(S+'/vouchers/zamowienie.html', 'shop.example.com')
        assert code == 200 and hdr['Strict-Transport-Security'] == 'max-age=86400' and "frame-ancestors 'none'" in hdr['Content-Security-Policy']
        assert hdr['X-Robots-Tag'] == 'noindex, nofollow'
        code, hdr, _ = status(H+'/vouchers/zamowienie.html?x=1', 'shop.example.com')
        assert code == 308 and hdr['Location'] == 'https://shop.example.com/vouchers/zamowienie.html?x=1'
        for host in ['shop.example.com', 'panel.example.com']:
            for path in (['/vouchers/zamowienie.html', '/vouchers/api/order.php'] if host.startswith('shop') else ['/panel/', '/api/panel.php']):
                code, hdr, _ = status(H+path, host, {'X-Forwarded-Proto': 'https', 'X-Forwarded-Host': 'attacker.invalid'})
                assert code == 308 and hdr['Location'].startswith(f'https://{host}/'), (host, path, code)
        assert status(H+'/vouchers/zamowienie.html', 'attacker.invalid')[0] == 403
        for path in ['/vouchers/api/lib.php', '/vouchers/api/config.php', '/vouchers/api/release-id.php']:
            assert status(S+path, 'shop.example.com')[0] == 403, path
        # Absent from the shop tree; FilesMatch may answer 403 before the file lookup's 404.
        for path in ['/vouchers/api/panel.php', '/vouchers/panel/']:
            assert status(S+path, 'shop.example.com')[0] in (403, 404), path
        code, hdr, _ = status(S+'/', 'panel.example.com')
        assert code == 308 and hdr['Location'].endswith('/panel/'), (code, hdr)
        code, hdr, html = status(S+'/panel/', 'panel.example.com')
        assert code == 200 and b'panel.js' in html
        assert hdr['X-Robots-Tag'] == 'noindex, nofollow' and hdr['Strict-Transport-Security'] == 'max-age=86400'
        assert "frame-ancestors 'none'" in hdr['Content-Security-Policy'] and 'paymove' not in hdr['Content-Security-Policy']
        assert status(S+'/api/panel.php', 'panel.example.com')[0] == 200
        for path in ['/api/lib.php', '/api/panel-lib.php', '/api/release-id.php']:
            assert status(S+path, 'panel.example.com')[0] == 403, path
        for path in ['/api/order.php', '/api/webhook.php', '/api/status.php', '/zamowienie.html']:
            assert status(S+path, 'panel.example.com')[0] in (403, 404), path
        code, hdr, _ = status(H+'/panel/', 'panel.example.com')
        assert code == 308 and hdr['Location'] == 'https://panel.example.com/panel/'
        # The real-host acceptance script, routed to this fixture. Apache here has no PHP handler,
        # so only the two "answers JSON" checks cannot pass; everything else must.
        import importlib.util, sys, urllib.parse
        spec = importlib.util.spec_from_file_location('acceptance', ROOT/'scripts/heartshop/http-acceptance.py')
        acc = importlib.util.module_from_spec(spec); spec.loader.exec_module(acc)
        def routed(url, headers=None):
            u = urllib.parse.urlsplit(url)
            target = f"{u.scheme}://127.0.0.1:{https if u.scheme == 'https' else http}{u.path}" + (f'?{u.query}' if u.query else '')
            with get(target, dict(headers or {}, Host=u.hostname)) as r:
                return r.status, r.headers, r.read(65536)
        acc.get = routed
        argv, sys.argv = sys.argv, ['http-acceptance.py', 'https://shop.example.com/vouchers/', '--panel', 'https://panel.example.com']
        import contextlib, io
        try:
            with contextlib.redirect_stdout(io.StringIO()):  # expected fixture failures are asserted below
                acc.main()
        except SystemExit:
            pass
        finally:
            sys.argv = argv
        assert acc.failures == ['shop order.php GET answers JSON', 'shop API not cached', 'panel session endpoint answers JSON'], acc.failures
        print('PASS mounted Apache: /vouchers on the website host, panel host routes only panel.php, HTTPS/XFP/host rules on both')

    apache.check(layout=layout, extra=exercise)

    # ---------- indexing a mounted shop: canonical under /vouchers, website root files untouched ----------
    live = mounted.replace("'production_ready' => false", "'production_ready' => true").replace("'enabled' => false", "'enabled' => true")
    live = re.sub(r"'demo'(\s*)=> true", r"'demo'\1=> false", live)
    live = re.sub(r"'invoice_request_text' => '[^']*'", "'invoice_request_text' => 'Fakturę wystawimy na prośbę wysłaną mailem.'", live)
    config.write_text(live)
    printed = run(['php', 'scripts/heartshop/build-storefront.php'], repo)
    assert f'{SHOP}/vouchers/' in printed, printed
    html = (repo/'public/heartshop/index.html').read_text()
    assert f'<link rel="canonical" href="{SHOP}/vouchers/">' in html and '/heartshop/' not in html
    assert "m#^/vouchers/(index\\.html)?$#" in (repo/'public/heartshop/.htaccess').read_text()
    assert all((repo/'public'/f).read_bytes() == b for f, b in roots.items())
    print('PASS mounted layout: base/mount/panel validation, release trees, one database for two hosts, host-only panel cookie, origin separation, XFP spoof, indexing under /vouchers')
