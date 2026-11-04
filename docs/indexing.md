# Merchant indexing and public facts

`indexing.enabled` in `public/heartshop/api/config.php` is the **one merchant
switch**. Default: `false`. Do not edit robots meta tags or the generated Apache
block independently. It is a build-time setting, not an environment variable.

```sh
php scripts/heartshop/build-storefront.php
php scripts/heartshop/build-storefront.php --check
```

Run the build after every price, seller, offer, description, invoice-instruction
or indexing change, and publish config + generated files atomically. `--check`
fails on stale generated files; CI runs it. The API remains the checkout's price
authority. Generated fallback HTML and schema prices use the same integer-grosze
value as `makeSnapshot`; do not keep a second price list in HTML.

## Enabling production indexing

1. Finish merchant content and sandbox acceptance in `production.md`. Set the
   actual default offer's `demo=false` and `production_ready=true`. The builder
   refuses indexing with either gate still closed. These flags do not replace UAT.
2. Set `indexing.origin` to the exact, allowed HTTPS production origin, also used
   by `SHOP_ORIGIN`. Keep staging in a separate build with `enabled=false`.
3. Set the real offer name, seller, PL/EN descriptions and approved public seller
   address (or leave the address empty). For services choose `ProfessionalService`
   or `LocalBusiness` to match the seller. Review **all** remaining hand-written
   copy, images, duration, voucher validity, terms and demo labels yourself.
   The generator does not certify the truth or legal adequacy of that copy.
4. Turn `indexing.enabled=true`, build, review the generated diff, and run the
   full suite. Only `/heartshop/` (and Goods `/heartshop/en/`) becomes indexable.
   `index.html` aliases canonicalize to these directories. Query parameters are
   omitted from canonical URLs. Canonical/OG values never reflect the request Host.
5. Inspect the deployed headers AND HTML, including private routes. Retain the
   actual hosting response as acceptance evidence; a CDN may add a second noindex.

```sh
curl -sSI https://YOUR-HOST/heartshop/
curl -sS https://YOUR-HOST/heartshop/ | rg 'robots|canonical|og:|application/ld'
curl -sSI https://YOUR-HOST/heartshop/zamowienie.html
curl -sSI https://YOUR-HOST/heartshop/api/status.php
curl -sS https://YOUR-HOST/sitemap.xml
curl -sS https://YOUR-HOST/robots.txt
```

The Apache generated block permits `index, follow` **only** on the configured
production Host over direct TLS. Every other path/host remains `noindex, nofollow`.
Checkout, status API, thank-you, printable vouchers, panel and legal templates
never enter the sitemap and keep noindex. Noindex is not authentication: order
links remain bearer links, and the panel requires a session.

`public/robots.txt` allows crawling so crawlers can see noindex. Do not add a global
`Disallow: /` to hide private pages. The generated sitemap is empty for demo and
lists only enabled landing pages for production. Submit it to your search tools
only after production verification. Search indexing and rich results are never
guaranteed.

Schema contains only configured facts that are also rendered in the visible
“Oferta i sprzedawca” / “Offer and seller” block: identity, description, optional
address, offer and PLN price. Goods uses Product/Offer; Vouchers uses a
LocalBusiness/ProfessionalService provider with a Service and Offer. No invented
reviews, stock levels, validity deadline, claims of certification or provider
partnerships are emitted. Metadata is merchant content, not Heartmade marketing.

The default `.htaccess` uses HSTS `max-age=86400`. After confirming direct TLS,
certificate renewal and every service on that host, recommend production
`max-age=31536000`. Do not add `includeSubDomains` or `preload` without a separate
domain-wide review. The root `/` redirects to `/heartshop/`; serve `public/` as
DocumentRoot so the root rules, robots and sitemap are deployed too.
