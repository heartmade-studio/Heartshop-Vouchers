# Security

Do not post credentials, order records or customer details in public issues. Use GitHub's private vulnerability reporting on this repository when enabled; otherwise contact Heartmade through https://heartmade.pl/ and ask for a private reporting channel before sending sensitive details.

Only `public/` may be served. Never deploy the local simulation or expose private SQLite/configuration. Payment completion requires a verified provider webhook, matching stored totals and consistent provider identity. Configure and verify each merchant deployment using `docs/production.md`.

This initial standalone version has automated regression coverage; it has not received an independent security audit. There is no promised response SLA or guaranteed maintenance period.
