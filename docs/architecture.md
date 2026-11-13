# Heartshop Vouchers: architecture and extraction boundaries

`public/heartshop/` contains the storefront and PHP API. `config/env.example` documents private deployment configuration. `scripts/heartshop/` contains operational CLI tools; `scripts/dev/` is an isolated local simulator. `tests/heartshop/` uses no live merchant accounts.

The first standalone version was extracted from the Heartmade website's shop component. Only the shop runtime, tests and selected workers were copied. Website navigation generators, website deployment workflows, business context, production configuration, databases, order history and the original repository's Git history are not included.

## Order lifecycle

1. Browser reads offers and prices from `api/order.php`.
2. Checkout submits a same-origin request with an idempotency key. The server validates inputs, freezes price/terms/delivery in an order snapshot and persists the payment attempt before calling PAYMOVE.
3. PAYMOVE returns a checkout URL. Browser callbacks are UX signals, not proof of payment.
4. The signed webhook changes payment state and enqueues mail in one SQLite transaction. A price or provider-order conflict becomes `review`; fulfillment stays blocked.
5. A separate worker sends queued email. Paid vouchers receive one code per unit; this distribution has no physical-order export.
6. The merchant redeems vouchers in the panel; invoice issuance remains outside this project.

SQLite WAL files and private configuration must have owner-only permissions outside the web root. The panel uses a password hash and hashed server-side session tokens. API responses are not cached; order status links are bearer links and must not be shared publicly.

## Standalone changes

- A `public/` web root separates runtime from tooling and data.
- Heartmade domain allowlists were replaced by example domains; the local launcher supplies its own trusted origin through process configuration.
- Explicit per-offer `demo` flags replace product-ID-specific demo behavior. Snapshot flags decide mail copy and fulfillment eligibility; changing today's config does not turn an old demo order into a real shipment.
- `production_ready` and non-demo offers are both required before creating production payments.
- Local simulation lives outside `public/`, rejects foreign Host/Origin and remote clients, uses synthetic configuration and prevents actual mail. It is not a provider verification result.
- Tests no longer rely on a contributor's home-directory tooling.
- Fonts ship with their OFL texts. Unverified candle photography is excluded.

The public repository becomes the reusable code source. The existing Heartmade-hosted demo is a separate consumer and has not been migrated automatically. Changes should be deliberately ported or adopted through a reviewed release; there is no bidirectional sync.

## Distribution boundary

Only voucher offers are configured and accepted. Shipping pages, imagery, Furgonetka endpoint, rewrite and credentials are excluded. Invoice collection is disabled; issuance is unimplemented. Shared internal calculation/panel helpers retain cross-offer regression coverage. `tests/heartshop/core.py` copies the code into a temporary directory and installs a synthetic dual-offer fixture; `standalone.py` independently tests the actual product configuration and its boundaries. No test configuration override exists in the deployed runtime.
