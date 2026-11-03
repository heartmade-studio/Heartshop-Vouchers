# Configure a real shop

Heartshop Vouchers includes production payment and fulfillment paths. A fresh clone deliberately cannot charge real money. The following setup turns it into your merchant deployment. Actual PAYMOVE acceptance is still required on your own accounts.

## Runtime and private data

Use PHP 8.5 with curl, sqlite3 and mbstring; Apache 2.4 with rewrite, headers, authz_core and TLS. Use direct HTTPS termination. Reverse proxies require a separately reviewed trusted-proxy configuration; never trust arbitrary `X-Forwarded-Proto`.

Set Apache `DocumentRoot` to the absolute `public/` directory and allow `.htaccess` rules there (`AllowOverride All`, `Options FollowSymLinks`). Enable rewrite/headers/SSL. Provide a valid TLS certificate for the configured domain. The store is served at `/heartshop/`; changing its base path requires coordinated PHP, HTML, cookie and web-server configuration.

```sh
umask 077
mkdir -p private/heartshop
chmod 700 private/heartshop
cp config/env.example private/heartshop/.env
chmod 600 private/heartshop/.env
php scripts/heartshop/panel-password.php
```

Edit the private `.env` with your credentials and the generated password-hash line. PHP and CLI workers must run as the data-directory owner; database, WAL and SHM files must be 0600. The default environment path is `private/heartshop/.env` relative to the repository root. An absolute `HEARTSHOP_ENV_FILE` process variable can override it; it must remain outside the web root. Separate every merchant's user, database, secrets and email configuration.

## Merchant configuration

1. Replace example hostnames in `public/heartshop/.htaccess` and `public/heartshop/api/config.php`. Set the identical HTTPS `SHOP_ORIGIN` in private `.env`. `HEARTSHOP_ORIGIN` is an optional server-side allowlist override used by the local launcher; never derive it from a request header. Remove it in production unless intentionally configured.
2. Edit offers, prices (integer grosze), tax settings, seller and order prefix in `config.php`. Preserve IDs/prefix after sales start. `masaz-60` is the sample service voucher. Set `default_offer` consistently; only voucher offers are accepted. A new offer needs its own matching HTML `data-offer` and paths.
3. Replace sample product/seller/contact copy, images and all demo text in the Polish HTML, including voucher/confirmation pages. Replace legal placeholders with your reviewed terms and privacy notice and set `terms_version`. The included documents are samples, not your merchant policy. Review robots/noindex deliberately if indexing is wanted; status, checkout and panel should remain private from indexing.
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
- Voucher issuance, printable code and redemption/undo. Establish the separate merchant invoicing process before live selling.
- Mail worker failure/retry and actual delivery; panel login/logout and unauthenticated rejection.
- HTTP redirects, allowed hosts, direct PHP-helper denial, private paths unreachable, backup restoration.

Finally perform an explicitly authorized small live transaction and its operational handling before announcing the merchant shop as live-verified. This repository's initial extraction did not perform real payments, shipment purchases, invoice issuance or outbound mail.

`review` and uncertain attempts require reconciliation against PAYMOVE; do not mark paid by editing SQLite without a reviewed recovery procedure. Refunds and double payments after a late completion are manual merchant operations. Inventory is not reserved or tracked by Heartshop.
