# Hosting options for a Heartmade-managed shop

Decision proposal, 2026-11-04. No provider has been selected, contacted or tested
with a merchant account. These are infrastructure costs, **not a Heartmade care
quote**, and do not include domain, payment fees or implementation work.

## Required runtime

- Apache 2.4: rewrite, headers, authz_core, TLS, `AllowOverride All`,
  `Options FollowSymLinks`, `DocumentRoot` pointing to `public/` only.
- PHP with curl, sqlite3 and mbstring, identical supported CLI/web versions;
  see the tested minimum in `production.md` and the PHP 8.2–8.5 CI matrix.
- Direct TLS with `HTTPS=on`; a reverse proxy needs a separately reviewed trusted
  proxy configuration. An “Apache-compatible” server is not acceptance evidence.
- Private writable storage outside webroot, per-shop OS user and 0700/0600
  permissions; SQLite locking and WAL on a local filesystem, not shared NFS.
- Cron once per minute, CLI mail worker, a working authenticated mail transport
  behind PHP `mail()`, delivery monitoring and domain mail authentication.
- Consistent SQLite `.backup` (or SQLite backup API) to storage outside webroot,
  off-host copy and tested restore. Host snapshots alone do not prove WAL safety.

LiteSpeed (common on Polish shared hosting) is not Apache. A first partial check on
one LiteSpeed host (2026-11-11, HTTP only) found that the server's own HTTPS redirect
and PHP's `$_SERVER['HTTPS']` trust a client-sent `X-Forwarded-Proto`, while the
shop's `.htaccess` `%{HTTPS}` rule did not. Keep that rule, never rely on a hosting
panel's "force SSL" option, and qualify such a host with
`scripts/heartshop/http-acceptance.py` over real TLS before accepting it.

## Comparison

| Option | Monthly infrastructure budget, net PLN | Who operates what | Fit and checks still needed |
|---|---:|---|---|
| Small OVHcloud VPS, Linux + Apache/PHP | Published VPS-1/2 from **16.32–30.77**; plan **25–60** including an estimated off-host backup allowance | Provider: hardware/network; Heartmade: OS updates, firewall, TLS, PHP, cron, mail, app, SQLite backups/restore, monitoring and incident response | Most direct match: Apache/direct TLS/user permissions can be controlled. Heartmade must explicitly accept ongoing system administration. OS image, PHP version and mail transport still need provisioning/UAT. |
| Polish shared hosting, LH.pl Kiwi/Mango candidate | Renewal **249–399/year**, equivalent **20.75–33.25/month** (annual payment); first-year promotions excluded | Provider: host OS/platform/backups; Heartmade: merchant config, releases, cron/mail, independent SQLite backup/restore and application incidents | Published PHP 8.2–8.5, cron, `.htaccess`, SSH and Let's Encrypt are promising. Before purchase confirm actual Apache 2.4, direct TLS, extensions, private paths, per-minute cron, CLI version and SQLite WAL/permissions. Not yet verified compatible. |

Prices read on 2026-11-04: [OVHcloud VPS pricing](https://www.ovhcloud.com/pl/vps/)
(the page labels the current cards “2027”, prices are “from” and depend on order
conditions); [LH.pl hosting comparison](https://www.lh.pl/hosting). LH lists
30-day daily backups, but their consistency for an active SQLite WAL database
still requires a restore test. VAT, commitment, renewal and extras must be checked
in the final quote. With 23% VAT, the VPS base range is about 20.07–37.85 gross;
LH renewal equivalents about 25.52–40.90 gross. The extra VPS backup allowance is
our planning estimate, not a provider quote.

## Recommendation, not a selection

Prefer a small dedicated VPS **if Heartmade accepts the operator role and its
cost**: it gives the clearest match to the tested Apache/direct-TLS model. Budget
roughly 1–2 engineering days for provisioning, backup/restore, mail and monitoring,
plus separately scheduled provider UAT. If minimizing system administration is
the priority, first qualify a Polish managed-hosting plan against every requirement
above and run the full Apache/TLS + mail/backup acceptance on it. Do not infer
compatibility from PHP availability or `.htaccess` support alone.

Open business decisions: account owner and invoice payer; hosting cost included
or separate; operator for patches/monitoring and payment/mail incidents; backup
retention/recovery targets; merchant handover and export process. Agree these
before deployment. No hosting cost or maintenance scope is added to the public
Heartmade offer by this document.
