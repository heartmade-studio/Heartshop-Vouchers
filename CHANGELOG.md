# Changelog

## v0.1.0 — 2026-11-13

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
- Two-sided 6×4-inch printable voucher with recipient preview while typing,
  print and in-browser PDF download (bundled jsPDF 4.2.1, MIT). Recipient is
  required for new orders; older vouchers without one print no name. Booking is by
  the buyer email; each voucher also shows its own number (voucher, mails,
  panel) for sellers who want to tell copies apart. Demo vouchers print no demo
  label. Invoice fields removed from the voucher checkout (manual invoice
  instructions unchanged).
- Retried payment creation re-checks the current production gates; the local
  simulator supports cancel → retry → pay on the same order.
- Seller panel: dark buttons readable again (the shop theme had made `--night`
  a light surface); buyer-email lookup field no longer uppercased.
- Orders record whether they were paid in the PAYMOVE sandbox. A real offer paid
  there is labelled as a test payment on the printed voucher, PDF and buyer mail.
- Postcode field renamed (`postal`) so Safari no longer offers passwords on it;
  PAYMOVE methods listed without fast bank transfers; booking note on two lines.
- Payment recovery: a lost PAYMOVE create response is resumed under the same
  `externalId` with the frozen order; resumed checkouts read the payment status
  first, signed failed transactions are audited and diagnostics stay data-free.
- Hosted sandbox PAY links; buyers choose the payment method in PAYMOVE Checkout;
  checkout links the previous order's status after an error.
- Buyer text hardening: control and bidi-override characters removed; names,
  recipients and dedications reject links so shop mail cannot carry phishing text.
- Mail envelope sender (`-f`) aligned with `MAIL_FROM`.

## 2026-11-03 — standalone extraction

Initial independent MIT distribution with synthetic local simulation, isolated
private storage and CI. No merchant deployment or provider acceptance implied.
