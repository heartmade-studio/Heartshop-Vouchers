# Configure a real shop

Heartshop Vouchers includes production payment and fulfillment paths. A fresh clone deliberately cannot charge real money. The following setup turns it into your merchant deployment. Actual PAYMOVE acceptance is still required on your own accounts.

## Runtime and private data

Use PHP 8.2–8.5 (tested minimum 8.2; prefer 8.5 for new installations) with curl, sqlite3 and mbstring; Apache 2.4 with rewrite, headers, authz_core and TLS. Use direct HTTPS termination. Reverse proxies require a separately reviewed trusted-proxy configuration; never trust arbitrary `X-Forwarded-Proto`.

Set Apache `DocumentRoot` to the absolute `public/` directory and allow `.htaccess` rules there (`AllowOverride All`, `Options FollowSymLinks`). Enable rewrite/headers/SSL. Provide a valid TLS certificate for the configured domain. The store is served at `/heartshop/` by default; see [layout](#layout-base-path-mounted-shop-and-separate-panel-host) to change the path, mount the shop inside another website or give the panel its own host.

```sh
umask 077
mkdir -p private/heartshop
chmod 700 private/heartshop
cp config/env.example private/heartshop/.env
chmod 600 private/heartshop/.env
php scripts/heartshop/panel-password.php
```

Edit the private `.env` with your credentials and the generated password-hash line. PHP and CLI workers must run as the data-directory owner; database, WAL and SHM files must be 0600. The default environment path is `private/heartshop/.env` relative to the repository root. An absolute `HEARTSHOP_ENV_FILE` process variable can override it; it must remain outside the web root. Separate every merchant's user, database, secrets and email configuration.

## Layout: base path, mounted shop and separate panel host

All three are build-time settings in `shop` of `api/config.php`; rebuild after changing them.

- `base`: the shop's URL path, one lowercase segment (`/heartshop`, `/vouchers`). The repository directory stays `public/heartshop/`; the release copies it to `<web root><base>/`. Pages, scripts and the panel use relative paths; return URLs, voucher links, canonical URLs and generated Apache rules follow `base`.
- `mount`: `root` (default) when `public/` is the whole web root, so the build owns the root redirect, `robots.txt` and `sitemap.xml`. `subdirectory` when the shop lives inside another website: the build never writes root files and prints the URL to add to that website's own sitemap. The website's deployment must then exclude the shop directory from any `rsync --delete` before the shop is first uploaded.
- `panel_origins`: an explicit list of HTTPS panel origins, no wildcards, distinct from the shop origins. When set, `PANEL_ORIGIN` in the private `.env` selects this environment's panel origin; panel POSTs must come from it, and the session cookie is host-only (no `Domain`) with path `/api/` on the panel host. The shop directory then has no panel entry point and the panel host has no checkout, status or webhook. Set `env_file` to an absolute private path outside every web root so both hosts share one `.env` and one database, run by the same OS user. The PAYMOVE webhook stays on the shop host: `https://SHOP/<base>/api/webhook.php`. On shared hosting check the web PHP `open_basedir` first: DirectAdmin-style hosts often limit each domain to its own directory, which makes a private path shared by two domains unreadable (the CLI usually has no such limit, so test from the web).

Generated Apache blocks (hosts from `shop.origins`, entry points, indexing) are rewritten by the build; edit the config, not the generated lines.

```sh
php scripts/heartshop/build-storefront.php
php scripts/heartshop/release.php /tmp/heartshop-release   # new, empty directory
```

`release.php` refuses a stale build and writes `shop/` (to `<web root><base>/`), `panel/` (the panel host's document root, only with `panel_origins`), `worker/` (the CLI mail worker and password tool, for a private directory outside every web root) and, for `mount: root`, `root/`. Every tree carries the same `api/release-id.php` (denied over HTTP): deploy all trees from one release directory and compare that file on the server. Uploading is a separate operator step.

After deployment and before any sandbox payment, keep the output of the read-only acceptance check on the real server:

```sh
python3 scripts/heartshop/http-acceptance.py https://SHOP/vouchers/ --panel https://PANEL
```

## Merchant configuration

1. Replace example hostnames in `shop.origins` (and `indexing.origin`) in `public/heartshop/api/config.php`, then rebuild: the build generates the `.htaccess` host rules. Set the identical HTTPS `SHOP_ORIGIN` in private `.env`. `HEARTSHOP_ORIGIN` is an optional server-side allowlist override used by the local launcher; never derive it from a request header. Remove it in production unless intentionally configured.
2. Edit offers, prices (integer grosze), tax settings, seller and order prefix in `config.php`. Preserve IDs/prefix after sales start. `masaz-60` is the sample service voucher. Set `default_offer` consistently; only voucher offers are accepted. A new offer needs its own matching HTML `data-offer` and paths.
3. Replace sample product/seller/contact copy, images and all demo text in the Polish HTML, including voucher/confirmation pages. Replace legal placeholders with your reviewed terms and privacy notice and set `terms_version`. The included documents are samples, not your merchant policy. Use the single indexing switch and build workflow in [indexing](indexing.md); do not edit the HTML/meta and Apache rules separately.
4. Keep `PAYMOVE_ENV=sandbox` while configuring credentials, product and webhook. Use [integration contracts](integrations.md). No production keys belong in Git, browser code, issues or logs.
5. For offers representing actual goods/services, set their `demo` field to `false`. Old orders retain their original demo snapshot. Invoice issuance is unimplemented and must be arranged outside this project; do not enable invoice collection without first implementing that process. Set `production_ready` to `true` only after the content and checks below are complete; then switch to your live PAYMOVE environment and credentials as a deliberate operator action.

## Email and operations

The worker is a CLI script outside the web root:

```sh
php scripts/heartshop/retry-mail.php --status /ABS/Heartshop-Vouchers/public/heartshop/api/lib.php
php scripts/heartshop/retry-mail.php --send /ABS/Heartshop-Vouchers/public/heartshop/api/lib.php
php scripts/heartshop/retry-mail.php --check /ABS/Heartshop-Vouchers/public/heartshop/api/lib.php
```

After testing the configured mail transport, schedule `--send` once per minute under the same OS user as PHP. `--check` reports stalled mail; arrange external monitoring of its exit status/output and the transport. `--status` does not send but may initialize the database. No cron is installed by this project.

Back up SQLite using its backup API/CLI `.backup`, including a tested restore; do not copy a live WAL database file alone. Store backups outside the web root, restrict access and set retention. Protect host access/error logs because order URLs are bearer links. For updates, use an atomic release or pause checkout while replacing a mutually compatible PHP/JS release. Never overwrite newly accepted orders during rollback.

## Acceptance before real selling

Run `bash scripts/heartshop/check.sh`, then exercise your HTTPS sandbox installation:

- Successful payment, cancellation, timeout, duplicate and delayed signed events, amount mismatch and changed provider ID. Only verified paid events may release goods or vouchers.
- Voucher issuance, printable/PDF voucher and redemption/undo after buyer-email lookup. Establish the separate merchant invoicing process before live selling.
- Mail worker failure/retry and actual delivery; panel login/logout and unauthenticated rejection.
- HTTP redirects, allowed hosts, direct PHP-helper denial, private paths unreachable, backup restoration.

Finally perform an explicitly authorized small live transaction and its operational handling before announcing the merchant shop as live-verified. This repository's initial extraction did not perform real payments, shipment purchases, invoice issuance or outbound mail.

`review` orders and attempts held with `payment_review` require reconciliation against PAYMOVE (uncertain creates recover automatically; see docs/integrations.md); do not mark paid by editing SQLite without a reviewed recovery procedure. Refunds and double payments after a late completion are manual merchant operations. Inventory is not reserved or tracked by Heartshop.

## Build and hosting acceptance

Follow [merchant indexing](indexing.md) and run the storefront builder and
`--check` after changing configuration, prices or invoice instructions. Publish
PHP config, generated HTML, root `.htaccess`, robots and sitemap atomically.
Root `/` redirects to `/heartshop/`. Test both roots and the Host/TLS noindex guard.
See [hosting options](hosting.md) for the open hosting/operator decision and the
production HSTS recommendation in [indexing](indexing.md).

## Printable voucher

`twoj-voucher.html` is a two-sided 6×4-inch voucher (front: recipient and dedication; back: service, booking details, expiry) that buyers print or download as a PDF generated in the browser with the bundled jsPDF. Its booking details (phone, address, website) are fictional sample text: replace them in the template before selling.

Booking is identified by the buyer's email address, which is printed on the voucher. Buyers therefore share that address with whoever receives the voucher; say so in your terms or checkout copy. The voucher pages and buyer email no longer show internal voucher codes; the seller finds vouchers in the panel by the buyer's email.

## Voucher contact line

`voucher_contact` in `api/config.php` is an optional single line (max 160 characters) that replaces the booking note on the printable voucher and is added to the buyer email, e.g. how to book the service. It is read from the current configuration, so a changed number also appears on older vouchers' pages; emails already sent keep the old text.

## Manual invoice and voucher terms review

Set `invoice_request_text` in `api/config.php` to the seller-approved instruction:
how to contact the seller, which order reference to quote, and how required
invoice details are supplied to the seller. The default suggests replying to the
order email, whose Reply-To is `MERCHANT_EMAIL`. Test that the mailbox is monitored.
The builder uses this text in FAQ and the terms template; checkout freezes it in
the order snapshot and the queued buyer email uses that snapshot on resend too.
Old orders without this field do not inherit a new promise. The code collects no
invoice details and issues no invoice; `invoice` must remain `false`. While the
supplied sample text (containing "przykładowa instrukcja") or an empty text is
configured, live PAYMOVE payments and indexing are refused.

The voucher terms are a template for review, not legal advice. Complete the
placeholders for validity, redemption, transferability, non-cash-exchangeability,
appointment cancellation and withdrawal from the service/voucher purchase.
Check consistency with `valid_months`, actual service delivery and the seller's
booking process. Do not interpret “no cash exchange” as a legal conclusion about
statutory rights. No booking system or automatic refund is implemented.

**Consult your accountant: fiscal-receipt obligation and VAT treatment of
single-purpose vouchers.** Ask which treatment fits this actual voucher and
seller, including the timing of tax recognition; do not infer it from the example
`tax_rate=23`. Also obtain legal review of service withdrawal/cancellation text.
These are questions to resolve before launch, not conclusions supplied by the code.
