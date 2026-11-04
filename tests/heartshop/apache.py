#!/usr/bin/env python3
"""Exercise the real .htaccess on isolated loopback Apache, never the live shop.
No PHP handler, credentials, mail or payment calls. Requires Apache + openssl.
"""
import grp
import os
from pathlib import Path
import pwd
import shutil
import socket
import ssl
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
BINARY = shutil.which('httpd') or shutil.which('apache2') or '/usr/sbin/httpd'
MODULES = next((p for p in [Path('/usr/libexec/apache2'), Path('/usr/lib/apache2/modules')]
                if p.is_dir()), None)
assert MODULES, 'Apache modules missing; install Apache, do not skip this gate'


def port():
    with socket.socket() as s:
        s.bind(('127.0.0.1', 0))
        return s.getsockname()[1]


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def check(extra=None):
    scratch = Path(tempfile.gettempdir())
    with tempfile.TemporaryDirectory(prefix='heartshop-apache-', dir=scratch) as temp:
        base = Path(temp)
        if extra:
            shutil.copytree(ROOT, base/'repo', ignore=shutil.ignore_patterns('.git','var','private','__pycache__'))
        web = base/'repo/public' if extra else base/'www'
        site = web / 'heartshop'
        if not extra:
            site.mkdir(parents=True)
            shutil.copy2(ROOT / 'public/heartshop/.htaccess', site / '.htaccess')
            (site / 'assets').mkdir()
            (site / 'api').mkdir()
            (site / 'zamowienie.html').write_text('synthetic checkout')
            (site / 'assets/checkout.js').write_text('// synthetic asset')
            for name in ['config', 'lib', 'vouchers', 'panel-lib', 'future-private-helper']:
                (site / f'api/{name}.php').write_text('MUST NOT BE SERVED')
            (site / 'api/panel.php').write_text('reviewed entry point')
        cert, key = base / 'cert.pem', base / 'key.pem'
        subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes',
                        '-keyout', str(key), '-out', str(cert), '-days', '1',
                        '-subj', '/CN=localhost', '-addext', 'subjectAltName=IP:127.0.0.1'],
                       check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        http, https = port(), port()
        assert http != https
        modules = ['mpm_event', 'unixd', 'authz_core', 'authz_host', 'dir', 'rewrite', 'headers', 'ssl', 'socache_shmcb']
        # Ubuntu compiles unixd into Apache; macOS ships it as a DSO.
        # Ask the actual binary instead of assuming a module layout by OS.
        compiled = subprocess.check_output([BINARY, '-l'], text=True).split()
        builtins = {Path(name).stem.removeprefix('mod_') for name in compiled if name.endswith('.c')}
        loads = '\n'.join(f'LoadModule {m}_module "{MODULES}/mod_{m}.so"' for m in modules if m not in builtins)
        config = base / 'httpd.conf'
        config.write_text(f'''ServerRoot "{base}"
PidFile "{base}/httpd.pid"
ServerName localhost
Listen 127.0.0.1:{http}
Listen 127.0.0.1:{https}
{loads}
User {pwd.getpwuid(os.getuid()).pw_name}
Group {grp.getgrgid(os.getgid()).gr_name}
ErrorLog "{base}/error.log"
LogLevel warn
DocumentRoot "{web}"
<Directory "{web}">
  Options FollowSymLinks
  AllowOverride All
  Require all granted
</Directory>
<VirtualHost 127.0.0.1:{https}>
  SSLEngine on
  # Fixture only: macOS LibreSSL interoperability with newer Python OpenSSL.
  SSLProtocol -all +TLSv1.2
  SSLCertificateFile "{cert}"
  SSLCertificateKeyFile "{key}"
</VirtualHost>
''')
        subprocess.run([BINARY, '-t', '-f', str(config)], check=True)
        process = subprocess.Popen([BINARY, '-f', str(config), '-DFOREGROUND'],
                                   stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
        assert process.stderr is not None
        try:
            for _ in range(100):
                if process.poll() is not None:
                    raise RuntimeError(process.stderr.read().decode())
                try:
                    with socket.create_connection(('127.0.0.1', http), timeout=.1):
                        break
                except OSError:
                    time.sleep(.05)
            else:
                raise RuntimeError('Apache did not start')
            context = ssl.create_default_context(cafile=str(cert))
            context.maximum_version = ssl.TLSVersion.TLSv1_2  # Isolated LibreSSL fixture only.
            opener = urllib.request.build_opener(NoRedirect(), urllib.request.HTTPSHandler(context=context))

            def get(url, headers=None):
                try:
                    return opener.open(urllib.request.Request(url, headers=headers or {}), timeout=3)
                except urllib.error.HTTPError as e:
                    return e

            if extra:
                extra(get, http, https, web)
                return

            for path in ['zamowienie.html', 'assets/checkout.js']:
                # A client-controlled Host/forwarding header cannot choose redirect authority or bypass TLS.
                with get(f'http://127.0.0.1:{http}/heartshop/{path}?check=1',
                         {'Host': 'attacker.invalid', 'X-Forwarded-Proto': 'https'}) as response:
                    assert response.status == 403, (path, response.status)
                    assert 'Location' not in response.headers
                for host, target in [('shop.example.com', 'shop.example.com'), ('staging.shop.example.com', 'staging.shop.example.com'),
                                     ('www.shop.example.com', 'shop.example.com')]:
                    with get(f'http://127.0.0.1:{http}/heartshop/{path}?check=1',
                             {'Host': host, 'X-Forwarded-Proto': 'https'}) as response:
                        assert response.status == 308, (host, path, response.status)
                        assert response.headers['Location'] == f'https://{target}/heartshop/{path}?check=1', response.headers['Location']
                        assert 'Strict-Transport-Security' not in response.headers
                with get(f'https://127.0.0.1:{https}/heartshop/{path}', {'Host': 'www.shop.example.com'}) as response:
                    assert response.status == 308 and response.headers['Location'] == f'https://shop.example.com/heartshop/{path}'
                with get(f'https://127.0.0.1:{https}/heartshop/{path}') as response:
                    assert response.status == 200, (path, response.status)
                    assert response.headers['Strict-Transport-Security'] == 'max-age=86400'
                    assert response.headers['Referrer-Policy'] == 'no-referrer'
                    assert response.headers['X-Content-Type-Options'] == 'nosniff'
                    assert "frame-ancestors 'none'" in response.headers['Content-Security-Policy']
            with get(f'https://127.0.0.1:{https}/heartshop/api/panel.php') as response:
                assert response.status == 200, response.status
            for name in ['config', 'lib', 'vouchers', 'panel-lib', 'future-private-helper']:
                with get(f'https://127.0.0.1:{https}/heartshop/api/{name}.php') as response:
                    assert response.status == 403, (name, response.status)
                    assert b'MUST NOT BE SERVED' not in response.read()
            print('PASS Apache: listed hosts redirect to themselves, www to apex; spoofed host/proto rejected; HTTPS serves; HSTS/CSP/referrer; panel entry point served, private includes denied')
        except Exception:
            if (base / 'error.log').exists():
                print((base / 'error.log').read_text())
            raise
        finally:
            process.terminate()
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=5)
            process.stderr.close()


if __name__ == '__main__':
    check()
