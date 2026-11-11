#!/usr/bin/env python3
"""Read-only HTTP acceptance of a deployed shop (and optional separate panel host).

    python3 scripts/heartshop/http-acceptance.py https://example.com/vouchers/ [--panel https://panel.example.com]

Only GET requests with full certificate verification: no login, order, webhook or payment.
It checks what the Apache test suite checks locally, on the real web server (e.g. LiteSpeed):
HTTPS redirects, a spoofed X-Forwarded-Proto, security headers, denied includes and route separation.
Exit status 1 when any check fails. Keep the output as acceptance evidence.
"""
import argparse, ssl, sys, urllib.error, urllib.parse, urllib.request

INCLUDES = ['lib', 'config', 'panel-lib', 'vouchers', 'reliability', 'checkout-state', 'release-id']
failures = []


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


opener = urllib.request.build_opener(NoRedirect(), urllib.request.HTTPSHandler(context=ssl.create_default_context()))


def get(url, headers=None):
    req = urllib.request.Request(url, headers=dict({'User-Agent': 'heartshop-acceptance'}, **(headers or {})))
    try:
        r = opener.open(req, timeout=15)
    except urllib.error.HTTPError as e:
        r = e
    with r:
        return r.status, r.headers, r.read(65536)


def check(name, ok, detail=''):
    print(('PASS ' if ok else 'FAIL ') + name + (f'  [{detail}]' if detail else ''))
    if not ok:
        failures.append(name)


def plain(url):
    return 'http://' + url[len('https://'):]


def headers_ok(label, h, indexable=False):
    check(f'{label}: HSTS', (h.get('Strict-Transport-Security') or '').startswith('max-age='), h.get('Strict-Transport-Security'))
    check(f'{label}: nosniff', h.get('X-Content-Type-Options') == 'nosniff')
    check(f'{label}: no referrer', h.get('Referrer-Policy') == 'no-referrer')
    check(f'{label}: CSP frame-ancestors', "frame-ancestors 'none'" in (h.get('Content-Security-Policy') or ''))
    robots = h.get_all('X-Robots-Tag') or []
    check(f'{label}: robots header', robots == (['index, follow'] if indexable else ['noindex, nofollow']), str(robots))


def denied(label, url):
    code, _, body = get(url)
    check(f'{label} denied', code in (403, 404) and b'<?php' not in body, str(code))


def redirect(label, url):
    code, h, _ = get(plain(url))
    check(f'{label}: HTTP redirects to HTTPS', code in (301, 308) and h.get('Location') == url, f"{code} {h.get('Location')}")
    code, h, body = get(plain(url), {'X-Forwarded-Proto': 'https', 'X-Forwarded-Host': 'attacker.invalid'})
    check(f'{label}: spoofed X-Forwarded-Proto over HTTP is not served', code != 200 and 'attacker' not in (h.get('Location') or ''), str(code))


def main():
    a = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    a.add_argument('shop', help='HTTPS shop URL ending with /, e.g. https://example.com/vouchers/')
    a.add_argument('--panel', help='HTTPS origin of a separate panel host')
    a.add_argument('--indexed', action='store_true', help='expect the landing page to be indexable')
    args = a.parse_args()
    shop = args.shop
    if not shop.startswith('https://') or not shop.endswith('/'):
        sys.exit('Shop URL must be https:// and end with /')

    code, h, _ = get(shop)
    check('shop landing 200', code == 200, str(code))
    headers_ok('shop landing', h, args.indexed)
    code, h, _ = get(shop + 'zamowienie.html')
    check('shop checkout page 200', code == 200, str(code))
    headers_ok('shop checkout', h)
    redirect('shop checkout', shop + 'zamowienie.html')
    code, h, _ = get(shop + 'api/order.php')
    check('shop order.php GET answers JSON', code in (200, 503) and 'json' in (h.get('Content-Type') or ''), str(code))
    check('shop API not cached', h.get('Cache-Control') == 'no-store', h.get('Cache-Control'))
    for name in INCLUDES:
        denied(f'shop api/{name}.php', shop + f'api/{name}.php')
    denied('shop .htaccess', shop + '.htaccess')

    if args.panel:
        panel = args.panel.rstrip('/')
        if not panel.startswith('https://'):
            sys.exit('Panel origin must be https://')
        denied('shop api/panel.php (panel has its own host)', shop + 'api/panel.php')
        denied('shop panel/ (panel has its own host)', shop + 'panel/')
        code, h, _ = get(panel + '/')
        check('panel root redirects to /panel/', code in (301, 302, 307, 308) and (h.get('Location') or '').endswith('/panel/'), f"{code} {h.get('Location')}")
        code, h, body = get(panel + '/panel/')
        check('panel page 200', code == 200 and b'panel.js' in body, str(code))
        headers_ok('panel page', h)
        redirect('panel page', panel + '/panel/')
        code, h, _ = get(panel + '/api/panel.php?action=session')
        check('panel session endpoint answers JSON', code in (200, 503) and 'json' in (h.get('Content-Type') or ''), str(code))
        for name in ['order', 'status', 'webhook']:
            denied(f'panel host api/{name}.php', panel + f'/api/{name}.php')
        denied('panel host shop page', panel + '/zamowienie.html')
        for name in INCLUDES:
            denied(f'panel api/{name}.php', panel + f'/api/{name}.php')
    else:
        code, _, _ = get(shop + 'api/panel.php?action=session')
        check('panel API on shop host answers', code in (200, 503), str(code))

    print(f'{len(failures)} failed' if failures else 'ALL PASS')
    sys.exit(1 if failures else 0)


if __name__ == '__main__':
    main()
