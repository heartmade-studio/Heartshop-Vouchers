# Contributing

Small, focused pull requests are welcome. Read `AGENTS.md`, `docs/architecture.md`
and the edition boundary before changing behavior. Explain the user-visible
problem, the proposed behavior and validation. Discuss new integrations or scope
expansion before implementation; keep PAYMOVE and this edition's product type.

Use a feature branch and synthetic fixtures only. Never commit merchant names,
orders, credentials, real contact details, `.env` files, SQLite databases or
provider exports. Report vulnerabilities through `SECURITY.md`.

For runtime changes run:

```sh
php scripts/heartshop/build-storefront.php
bash scripts/heartshop/check.sh
git diff --check
```

The full suite needs PHP with curl/sqlite3/mbstring, Node 22+, Python 3,
Apache 2.4 and OpenSSL. HTTP tests bind loopback; no real payments, mail or
shipments are authorized by them. CI exercises PHP 8.2, 8.3, 8.4 and 8.5.

Do not weaken signed-event validation, idempotency, server pricing, snapshots,
private-file boundaries or demo production gates. Generated storefront changes
must come from config plus the builder, with visible copy reviewed for parity.
Add behavior tests where the change affects purchases or security. Update
CHANGELOG and relevant setup docs. Keep release, merchant UAT and deployment
claims separate; a PR is not a release or authorization to deploy.
