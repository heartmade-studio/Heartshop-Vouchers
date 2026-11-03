# Deliberately chosen integrations

## PAYMOVE.io

[PAYMOVE](https://www.paymove.io/) offers agent-oriented payments as well as REST and embedded checkout. This implementation uses **REST + browser widget + signed webhooks**, not MCP or an autonomous agent. Merchant onboarding, product setup and live/sandbox credentials are separate.

- [REST contract](https://docs.paymove.io/en/rest-api): POST `/api/pay/product/{productId}/subproduct/pricing`; `X-API-KEY`; integer `price` in grosze; unique merchant `externalId`; `details.returnUrl`. PLN only.
- Sandbox base: `https://gateway-api.sandbox.paymove.io`; production: `https://api.paymove.io`.
- A successful create returns HTTP 200 and `redirectUrl`. The query parameter called `externalId` in that URL is PAYMOVE's payment hash, not Heartshop's external ID. Heartshop stores the full URL.
- [Payment events](https://docs.paymove.io/en/payment-status): flat `externalId`, `orderId`, `price`, `status`, `paymentMethod`. Configure the webhook to preserve these fields; template prices can be strings containing integer grosze.
- [Signature](https://docs.paymove.io/en/webhook-signature): `X-Paymove-Signature`, HMAC-SHA256 of timestamp + `.` + raw body, base64 signature, checked within five minutes.
- Webhook URL: `https://YOUR-HOST/heartshop/api/webhook.php`. Expected response is 200; configure retries. Do not fulfill based on the return URL or browser callback.

The initial provider identity binding comes from the first verified webhook, because the create response does not contain provider `orderId`. Conflicts and amount mismatches become `review`. Timeouts leave an uncertain payment requiring merchant reconciliation; do not erase the attempt to force another charge. Automatic refunds are not implemented.

## No shipping or invoice integration

This distribution has no Furgonetka endpoint, credentials or shipment flow. Invoice issuance is unimplemented; checkout does not collect invoice details. Arrange a separate invoice process before accepting real orders. Physical goods and Furgonetka belong to [Heartshop Goods](https://github.com/heartmade-studio/Heartshop-Goods).

## Email

Email uses PHP `mail()` via the host's transport. Configure domain authentication, delivery monitoring and a separate CLI worker. `sent` means accepted by the transport, not delivered to the inbox. Delivery is at least once: a crash can cause duplicate messages.
