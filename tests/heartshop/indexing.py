#!/usr/bin/env python3
"""Real Apache header + HTML tests against generated default and indexed builds.
No provider calls; Apache serves static pages, not PHP. Pricing parity uses PHP CLI.
"""
import json, re, subprocess
import apache

def exercise(get, http, https, web):
    repo = web.parent
    def build(check=False, ok=True):
        p = subprocess.run(['php','scripts/heartshop/build-storefront.php']+(['--check'] if check else []), cwd=repo, text=True, capture_output=True)
        assert (p.returncode==0)==ok, p.stdout+p.stderr
    def response(path, host='shop.example.com'):
        with get(f'https://127.0.0.1:{https}'+path, {'Host':host}) as r:
            return r.status, r.headers, r.read().decode()
    landings=['/heartshop/','/heartshop/index.html']
    if (web/'heartshop/en/index.html').exists():landings+=['/heartshop/en/','/heartshop/en/index.html']
    private=['/heartshop/zamowienie.html','/heartshop/dziekujemy.html','/heartshop/panel/','/heartshop/panel/index.html','/heartshop/api/status.php?o=synthetic']
    if (web/'heartshop/twoj-voucher.html').exists():private+=['/heartshop/twoj-voucher.html?o=synthetic']
    if len(landings)>2:private+=['/heartshop/en/order.html','/heartshop/en/thank-you.html']
    def verify(enabled):
        for path in landings+private:
            status, headers, html=response(path)
            assert status==200,(path,status)
            indexed=enabled and path in landings
            expected='index, follow' if indexed else 'noindex, nofollow'
            assert headers.get_all('X-Robots-Tag')==[expected],(path,headers)
            if '/api/' not in path:assert f'<meta name="robots" content="{expected}">' in html,path
            if indexed:
                url='https://shop.example.com'+('/heartshop/en/' if '/en/' in path else '/heartshop/')
                assert f'<link rel="canonical" href="{url}">' in html
                assert f'<meta property="og:url" content="{url}">' in html
                schema=json.loads(re.search(r'<script type="application/ld\+json">(.*?)</script>',html,re.S)[1])
                seller,product=schema['@graph'];offer=product['offers']
                assert seller['name']=='Synthetic seller & studio' and seller['name'].replace('&','&amp;') in html
                assert product['name'] in html and product['description'] in html
                assert offer['price']=='123.45' and offer['priceCurrency']=='PLN'
                assert '123,45' in html
                assert product['@type'] in ['Product','Service']
                if product['@type']=='Service':assert seller['@type']=='ProfessionalService'
            else:assert 'application/ld+json' not in html
        for path in landings:
            assert response(path, 'staging.shop.example.com')[1]['X-Robots-Tag']=='noindex, nofollow'
            assert response(path, 'attacker.invalid')[1]['X-Robots-Tag']=='noindex, nofollow'
        _,_,sitemap=response('/sitemap.xml')
        assert ('<loc>' in sitemap)==enabled
        assert all(x not in sitemap for x in ['zamowienie','twoj-voucher','panel','status.php','dziekujemy','order.html','thank-you'])
        _,_,robots=response('/robots.txt');assert 'Disallow: /' not in robots
        assert ('Sitemap:' in robots)==enabled
        with get(f'http://127.0.0.1:{http}/') as r:assert r.status==308 and r.headers['Location'].endswith('/heartshop/')
    build();build(check=True);verify(False)
    p=web/'heartshop/api/config.php';original=p.read_text()
    # A single index switch cannot accidentally publish the supplied demo.
    p.write_text(original.replace("'enabled' => false", "'enabled' => true"));build(ok=False)
    assert 'noindex' in (web/'heartshop/index.html').read_text()
    configured=p.read_text().replace("'production_ready' => false","'production_ready' => true")
    configured=re.sub(r"'demo'(\s*)=> true",r"'demo'\1=> false",configured)
    configured=re.sub(r"'price'(\s*)=> \d+",r"'price'\1=> 12345",configured, count=1)
    configured=re.sub(r"'seller'\s*=> '[^']*'", "'seller' => 'Synthetic seller & studio'",configured)
    p.write_text(configured);build(check=True,ok=False);build();build(check=True);verify(True)
    # Same gross unit price as the actual checkout snapshot; no parallel price list.
    code="require 'public/heartshop/api/lib.php'; $c=cfg(); echo json_encode(makeSnapshot(['qty'=>1,'delivery'=>'kurier'], $c));"
    snapshot=json.loads(subprocess.check_output(['php','-r',code],cwd=repo,text=True))
    assert snapshot['unit_price']==12345 and snapshot['items']==12345
    # Disabling again removes canonical/schema/sitemap and restores both noindex layers.
    p.write_text(configured.replace("'enabled' => true", "'enabled' => false"));build();verify(False)
    print('PASS indexing: Apache HTTP/TLS headers + meta, demo gate, production PL/EN, canonical/OG/schema, sitemap/robots, root redirect, price parity and rebuild drift')

apache.check(exercise)
