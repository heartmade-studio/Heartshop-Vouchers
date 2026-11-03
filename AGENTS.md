# Heartshop Vouchers

- Public runtime lives only in `public/`. Serve `public/` as the document root; never serve this repository root.
- Secrets, SQLite and mail workers stay outside `public/`. Never commit merchant records or credentials.
- Local simulation is in `scripts/dev/`, accepts loopback only and must never be deployed.
- Keep server-side pricing, signed payment events, idempotency and snapshot-based fulfillment intact.
- Demo offers cannot accept production payments. Production requires explicit merchant configuration and acceptance checks in `docs/production.md`.
- PAYMOVE.io is the intentional payment integration. Do not add Furgonetka; invoicing is currently unimplemented. Do not replace them without the owner's direction.
- Run `bash scripts/heartshop/check.sh` for runtime changes. The tests use synthetic data and do not authorize live transactions, mail or shipments.
- Do not deploy the original Heartmade website from this repository. It is a separate consumer; synchronization is explicit.
