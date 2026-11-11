# Changelog

## Unreleased — proposed v0.1.0

- Standalone Heartshop Vouchers with local simulation, PAYMOVE checkout and seller panel.
- One documented merchant indexing switch; generated canonical, OG, visible
  offer facts, structured data, sitemap and robots. Demo/private routes noindex.
- Config-generated price fallbacks with checkout parity and build-drift checks.
- Root redirect, hosting comparison and production HSTS guidance.
- PHP 8.2–8.5 CI matrix passed; documented minimum lowered to 8.2.
  Checkout uses Node 24; pinned setup-php already uses Node 24.
- Polish introduction, contributor guide and local simulation screenshot.
- Manual invoice-request copy in FAQ, terms and snapshot-based buyer email.
- Voucher legal review placeholders; Goods helpers quarantined under tests.
- Configurable shop `base` path, `mount: subdirectory` for a shop inside another
  website, and an optional separate seller-panel host (`panel_origins`,
  `PANEL_ORIGIN`). Hosts and entry points in `.htaccess` are generated from config;
  explicit `.ht*` denial. `release.php` assembles matching shop/panel trees;
  `http-acceptance.py` checks a deployed server read-only.
- Optional `voucher_contact` line on the printable voucher and buyer email.

No tag or release has been published for this proposal. Provider acceptance and
merchant deployment are separate from offline regression results.

## 2026-11-03 — standalone extraction

Initial independent MIT distribution with synthetic local simulation, isolated
private storage and CI. No merchant deployment or provider acceptance implied.
