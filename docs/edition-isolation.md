# Edition isolation follow-up

The configured offer type is enforced on the server before checkout. Synthetic
cross-edition configurations live only in `tests/fixtures/`; `core.py` installs
them into a temporary test copy. There is no deployment-time test override.

Goods-only NIP validation, invoice-address parsing, InPost lookups, shipping
validation and Furgonetka serialization have moved to
`tests/fixtures/goods-helpers.php`. The deployed runtime does not load that file.
The shipping-map CSP hosts and public Geowidget settings have also been removed.
Voucher configuration rejects enabling invoice collection.

Remaining follow-up: remove unreachable shipping/invoice branches in shared
validation, snapshots, mail, checkout JavaScript and panel/CSV presentation.
Database columns are retained for compatibility. Estimated engineering effort:
1–2 days including replacement of cross-edition tests with edition-specific
fixtures and old-order regression checks. These remnants provide no shipping
endpoint or invoicing capability.
