# Deliberately chosen integrations

## PAYMOVE.io

[PAYMOVE](https://www.paymove.io/) offers agent-oriented payments as well as REST and embedded checkout. This implementation uses **REST + browser widget + signed webhooks**, not MCP or an autonomous agent. Merchant onboarding, product setup and live/sandbox credentials are separate.

- [REST contract](https://docs.paymove.io/en/rest-api): POST `/api/pay/product/{productId}/subproduct/pricing`; `X-API-KEY`; integer `price` in grosze; unique merchant `externalId`; `details.returnUrl`. PLN only.
- Sandbox base: `https://gateway-api.sandbox.paymove.io`; production: `https://api.paymove.io`.
- A successful create returns HTTP 200 and `redirectUrl`. The query parameter called `externalId` in that URL is PAYMOVE's payment hash, not Heartshop's external ID. Heartshop stores the full URL.
- [Payment events](https://docs.paymove.io/en/payment-status): flat `externalId`, `orderId`, `price`, `status`, `paymentMethod`. Configure the webhook to preserve these fields; template prices can be strings containing integer grosze.
- [Signature](https://docs.paymove.io/en/webhook-signature): `X-Paymove-Signature`, HMAC-SHA256 of timestamp + `.` + raw body, base64 signature, checked within five minutes.
- Webhook URL: `https://YOUR-HOST/heartshop/api/webhook.php`. Expected response is 200; configure retries. Do not fulfill based on the return URL or browser callback.

Use a TRANSACTION webhook subscription. PAYMOVE has deprecated PAYMENT subscriptions. Set `PAYMOVE_TRANSACTION_SECRET` to that webhook's own signing secret. Send the default payload or include `type`, `externalId`, `orderId`, `status`, `currency`, `amountMinor` and `paymentMethod` in the template. Only signed PLN events of type `PAYMENT` or `REFUND` are accepted. The shop matches the gross PLN amount. The legacy PAYMENT subscription can remain active with its separate secret (`PAYMOVE_WEBHOOK_SECRET`) during migration. That payload has no `type` or `currency`, so remove the secret from the `.env` once the TRANSACTION subscription is verified. Either secret alone is enough to start the shop.

PAYMOVE refunds are always full. A refund notification carries the merchant `externalId` and the refunded payment's `orderId` as `parentOrderId`; include `{{parentOrderId}}` in a custom template. A signed `COMPLETED` refund of the transaction that paid an order (`paid` or `review`) moves the order to `refunded`. Its voucher codes can then no longer be redeemed or unredeemed, the buyer's status page stops showing them, and the seller gets an email listing the withdrawn codes. A refund of a different transaction for the same order, such as a duplicate payment, changes nothing. Heartshop never starts a refund; refunds remain the merchant's action in PAYMOVE.

PAYMOVE can send a signed TRANSACTION completion with an empty merchant `externalId`. Heartshop saves its transaction `orderId`, gross PLN amount, method, receipt time and payment scope in `signed_completions`. The thank-you page and the cron worker check the PAYMOVE status endpoint for the checkout URL stored on each candidate order. A `COMPLETED` response with the same transaction `orderId` binds the signed event to that order. The usual signed amount and fulfillment checks then run. An unsigned status response alone can change the thank-you message but cannot issue a voucher.

The matching window starts at order creation or the most recent time checkout served its payment link, whichever is later. Both that activity and the signed completion must be within the past 24 hours, and the completion must have arrived at or after the activity. Polling the status page does not extend the window. For older orders or events, follow the [manual reconciliation procedure](production.md#payment-reconciliation).

To retire an old webhook, PATCH its endpoint to an HTTPS endpoint under your control that acknowledges and discards retired deliveries. PAYMOVE supports only GET and PATCH on `/webhook/{id}`. Assignment supports only POST, so the API cannot delete or unassign the webhook. Verify the replacement TRANSACTION subscription before redirecting the old endpoint.

The initial provider identity binding comes from the first verified signed `COMPLETED` webhook, because the create response does not contain provider `orderId`. Conflicts and amount mismatches become `review`. Heartshop does not start refunds; it only records the ones PAYMOVE reports.

### Payment recovery

PAYMOVE's [REST contract](https://docs.paymove.io/en/rest-api#4-reusing-an-externalid) returns the original `redirectUrl` when the same `externalId` is posted again and ignores changed fields. Heartshop relies on this to recover a lost create response without a second payment:

- A retry always replays the stored order snapshot and total under the same `externalId`. Do not clear a browser attempt or delete an order to work around an uncertain result.
- `uncertain` may be retried after 3 seconds; `creating` is leased for 30 seconds, longer than the 20-second HTTP timeout. No database lock is held over HTTP, and a stale worker cannot overwrite a later result. `Retry-After` reports the remaining wait.
- New orders pin the PAYMOVE environment and product. A configuration change blocks replay into another product and requires review.
- Every resumed checkout reads the payment status from `pay-api.paymove.io` (sandbox: `pay-api.sandbox.paymove.io`) using the hash in the stored link. `PENDING`, `WAITING_FOR_EXTERNAL_ACTION` and `COMPLETED` show the processing page instead of another payable window. An API-level 404 means no transaction has started. Outages, unrecognized responses and unknown link formats never release a payment link. This unsigned read can only hold a payment back; it never fulfils an order, issues vouchers or sends mail.
- `CANCELED`/`ERROR` keep the same order and browser key, so the buyer can return and resume the same purchase with unchanged details. Changed details stay blocked for an existing attempt. The thank-you page offers resume only for a recent observation of `INITIALIZED`, `CANCELED` or `ERROR`. For processing states it hides resume and keeps polling, so a late signed completion replaces the cancellation.
- Signed failed transactions are recorded in `payment_transactions`. Only a successful payment binds `paymove_order_id`.
- Replayed creates are capped at 200 per 10 minutes and resumed status reads at 400 per 10 minutes, in addition to the per-address and new-order limits.
- `orders.payment_error` and PHP error logs contain only the failure category, HTTP status and cURL error number: no response bodies, buyer data, payment URLs or API keys.
- The browser key protects retries and reloads in the same tab. Other tabs, devices, cleared storage or an intentional new purchase are separate purchases; this is not customer-wide duplicate detection.

In sandbox, PAYMOVE may return a `pay.sandbox.paymove.io` link; the checkout opens it as the hosted sandbox payment page.

## No shipping or invoice integration

This distribution has no Furgonetka endpoint, credentials or shipment flow. Invoice issuance is unimplemented; checkout does not collect invoice details. Arrange a separate invoice process before accepting real orders. Physical goods and Furgonetka belong to [Heartshop Goods](https://github.com/heartmade-studio/Heartshop-Goods).

## Email

Email uses PHP `mail()` via the host's transport. Configure domain authentication, delivery monitoring and a separate CLI worker. `sent` means accepted by the transport, not delivered to the inbox. Delivery is at least once: a crash can cause duplicate messages.
