# Security Policy

## Reporting a vulnerability

Please report security issues privately through GitHub: open the **Security** tab of
this repository and choose **Report a vulnerability**. Do not open a public issue or pull
request for a suspected vulnerability.

Include the affected version, the steps to reproduce, and the impact you observed. We
acknowledge reports within 3 business days and aim to ship a fix for confirmed High or
Critical issues within 30 days. We credit reporters in the release notes unless you
ask us not to.

## Supported versions

Only the latest released version receives security fixes.

## Security model

- **Access control.** Every admin action checks a capability and a nonce. Admin checks go
through one filter, `mmi_data_pipeline_required_capability( $default )`; each check passes
  its default (`manage_woocommerce` or `manage_options`). Supplier URLs are fetched with
  `wp_safe_remote_*` (no internal hosts), and exports are stored in the private directory. REST routes have real
  `permission_callback`s. Inbound webhooks verify a signature with a constant-time compare.
- **Data isolation.** Customer-facing endpoints check that the requested record belongs to the
  logged-in customer. Credentials are stored server-side, shown masked, and never sent to the
  browser.
- **Private files** (feeds, exports, logs) are stored in a private directory with an unguessable
  name and served only through capability-checked handlers. Do not rely on `.htaccess`: nginx
  ignores it.
- **Audit log.** Security-relevant actions (settings and credential changes, exports,
  destructive or bulk operations, payment and fulfillment actions, denied requests, logins and
  role changes) are written to an append-only, hash-chained table, `{prefix}mmi_audit_log`.
  Administrators review it under **MannMade → Audit Log**. `wp mmi-audit verify` checks the
  chain for tampering. Retention defaults to 365 days (filter `mmi_audit_log_retention_days`).
  Secret values are redacted before storage.
- **Client IP** is taken from `REMOTE_ADDR`. Behind a proxy or CDN, configure your web server to
  restore the real client IP (nginx `real_ip`, Apache `mod_remoteip`) rather than trusting
  forwarded headers.
