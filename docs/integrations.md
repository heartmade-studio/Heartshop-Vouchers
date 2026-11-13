# Deliberately chosen integrations

## PAYMOVE.io

[PAYMOVE](https://www.paymove.io/) offers agent-oriented payments as well as REST and embedded checkout. This implementation uses **REST + browser widget + signed webhooks**, not MCP or an autonomous agent. Merchant onboarding, product setup and live/sandbox credentials are separate.

- [REST contract](https://docs.paymove.io/en/rest-api): POST `/api/pay/product/{productId}/subproduct/pricing`; `X-API-KEY`; integer `price` in grosze; unique merchant `externalId`; `details.returnUrl`. PLN only.
- Sandbox base: `https://gateway-api.sandbox.paymove.io`; production: `https://api.paymove.io`.
- A successful create returns HTTP 200 and `redirectUrl`. The query parameter called `externalId` in that URL is PAYMOVE's payment hash, not Heartshop's external ID. Heartshop stores the full URL.
- [Payment events](https://docs.paymove.io/en/payment-status): flat `externalId`, `orderId`, `price`, `status`, `paymentMethod`. Configure the webhook to preserve these fields; template prices can be strings containing integer grosze.
- [Signature](https://docs.paymove.io/en/webhook-signature): `X-Paymove-Signature`, HMAC-SHA256 of timestamp + `.` + raw body, base64 signature, checked within five minutes.
- Webhook URL: `https://YOUR-HOST/heartshop/api/webhook.php`. Expected response is 200; configure retries. Do not fulfill based on the return URL or browser callback.

The initial provider identity binding comes from the first verified signed `COMPLETED` webhook, because the create response does not contain provider `orderId`. Conflicts and amount mismatches become `review`. Automatic refunds are not implemented.

### Payment recovery

PAYMOVE's [REST contract](https://docs.paymove.io/en/rest-api#4-reusing-an-externalid) returns the original `redirectUrl` when the same `externalId` is posted again and ignores changed fields. Heartshop relies on this to recover a lost create response without a second payment:

- A retry always replays the stored order snapshot and total under the same `externalId`. Do not clear a browser attempt or delete an order to work around an uncertain result.
- `uncertain` may be retried after 3 seconds; `creating` is leased for 30 seconds, longer than the 20-second HTTP timeout. No database lock is held over HTTP, and a stale worker cannot overwrite a later result. `Retry-After` reports the remaining wait.
- New orders pin the PAYMOVE environment and product. A configuration change blocks replay into another product and requires review.
- Every resumed checkout reads the payment status from `pay-api.paymove.io` (sandbox: `pay-api.sandbox.paymove.io`) using the hash in the stored link. `PENDING`, `WAITING_FOR_EXTERNAL_ACTION` and `COMPLETED` show the processing page instead of another payable window. An API-level 404 means no transaction has started. Outages, unrecognized responses and unknown link formats never release a payment link. This unsigned read can only hold a payment back; it never fulfils an order, issues vouchers or sends mail.
- `CANCELED`/`ERROR` keep the same order and browser key, so the buyer can return and resume the same purchase with unchanged details. Changed details stay blocked for an existing attempt. The status page keeps polling, so a late signed completion replaces the cancellation.
- Signed failed transactions are recorded in `payment_transactions`. Only a successful payment binds `paymove_order_id`.
- Replayed creates are capped at 200 per 10 minutes and resumed status reads at 400 per 10 minutes, in addition to the per-address and new-order limits.
- `orders.payment_error` and PHP error logs contain only the failure category, HTTP status and cURL error number: no response bodies, buyer data, payment URLs or API keys.
- The browser key protects retries and reloads in the same tab. Other tabs, devices, cleared storage or an intentional new purchase are separate purchases; this is not customer-wide duplicate detection.

In sandbox, PAYMOVE may return a `pay.sandbox.paymove.io` link; the checkout opens it as the hosted sandbox payment page.

## No shipping or invoice integration

This distribution has no Furgonetka endpoint, credentials or shipment flow. Invoice issuance is unimplemented; checkout does not collect invoice details. Arrange a separate invoice process before accepting real orders. Physical goods and Furgonetka belong to [Heartshop Goods](https://github.com/heartmade-studio/Heartshop-Goods).

## Email

Email uses PHP `mail()` via the host's transport. Configure domain authentication, delivery monitoring and a separate CLI worker. `sent` means accepted by the transport, not delivered to the inbox. Delivery is at least once: a crash can cause duplicate messages.
