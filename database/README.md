# Database setup

For a fresh database, import `serveiq.sql`; it is the canonical current schema. The base script is a reset script: it drops and recreates its core tables, so do not run it against a database whose records must be preserved. The phase 5–10 SQL files are incremental upgrades for older schema states; inspect the actual schema before applying them because the canonical schema already includes those features.

Apply `phase11_security_audit.sql` after the existing phases. It creates the audit history table without replacing existing data. The application writes registration and authentication events there when this migration has been applied.

Apply `phase12_login_throttle.sql` after phase 11 to enable the login cooldown table. Login attempts are keyed by one-way hashes of the normalized identifier and client IP; raw credentials are never stored.

Apply `phase13_email_verification.sql` after phase 12. It keeps existing accounts verified for compatibility and adds OTP challenges for new registrations. Copy `.env.example` to `.env` for local setup; set `APP_ENV=development` and `MAIL_TRANSPORT=development_preview` only for local demonstrations. The code is displayed on the verification page and is never written to the database or application logs. SMTP mode uses `SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_FROM_EMAIL`, and `SMTP_FROM_NAME` environment values. SMTP delivery has not been verified until an actual configured server accepts a message.

For PII encryption, generate a private key with `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"` and set it as `APP_ENCRYPTION_KEY` in the ignored local `.env` file or server environment. Apply `phase14_sensitive_encryption.sql` once, then run `php services/encrypt_existing_pii.php` from the project root to encrypt existing provider phone/address and request address values in batches. New non-empty values are encrypted by the application. Keep the key backed up securely; encrypted values cannot be recovered without it. Phone/address columns are intentionally not indexed or searched.

For marketplace discovery, apply `phase15_marketplace_discovery.sql` after phase 14. It adds nullable provider/request coordinates and labelled provider response-time estimates. Existing rows remain valid with empty coordinates. The optional synthetic Pune seed, database-backed matching/filter test, and guarded rollback procedure are documented in [DEMO_MARKETPLACE.md](DEMO_MARKETPLACE.md); do not run the seed on production data.
