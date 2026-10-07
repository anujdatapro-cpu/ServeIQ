# ServeIQ Implementation Audit

This is a source and local-runtime audit checklist, not a claim that every browser/database workflow has been verified. The canonical schema, route tree, SQL mutation sites, POST forms, upload handlers, domain helpers, configuration, CSS/JavaScript assets, and test scripts were inventoried. Runtime verification used the PHP CLI at `D:\xampp\php\php.exe`. A live MySQL connection and configured SMTP delivery were not available.

## Current architecture and existing features

- PHP pages use shared session, authorization, CSRF, layout, database, and domain helper files.
- MySQL stores users, provider profiles/services, customer requests/images, ServiceDNA fingerprints, matching results, assessments, ADCS results, bookings/status history, and reviews.
- ServiceDNA and matching have deterministic helper implementations. AI enhancement has interface/mock/rule-enhanced clients and a deterministic fallback. ADCS compares provider assessments. Booking helpers define allowed transitions and schedule validation. Review helpers enforce completed booking eligibility and compute provider reputation.
- Customer, provider, and admin route groups call their corresponding server-side role guards. POST mutation handlers inspected use CSRF checks; an image removal subform missing its hidden token was found and fixed.
- Upload handlers accept a narrow set of raster image MIME types, validate image content, impose size/count caps, and generate server-side names. Provider image names use cryptographically random bytes. Apache rules deny direct `.env` access, directory listings, and PHP execution under uploads; private request-image delivery checks customer ownership.
- Marketplace discovery adds optional coordinates and labelled response-time estimates, provider filtering/sorting, and a repeatable synthetic Pune seed with rollback safeguards. These flows require phase 15 and a working database.
- Canonical `serveiq.sql` is a reset script. Phase SQL files are legacy upgrades and must not be blindly applied to a fresh canonical database.

## Checklist

| Area | Status | Evidence / remaining work |
|---|---|---|
| Sessions | Improved | Shared cookie configuration, strict session IDs, 30-minute configurable inactivity timeout, session rotation after registration/login, cookie expiry at logout. Browser behavior still needs HTTP/HTTPS verification. |
| Authentication | Partial | Password hashing and generic invalid-credential message exist. New registrations now require OTP verification. Live registration/login flow awaits database and SMTP configuration. |
| Login throttling | Added | Five failures per identifier/IP pair lead to a 15-minute cooldown; identifier and IP values are stored as one-way hashes. MySQL execution has not been verified. |
| CSRF | Partial | Static scan found 23 PHP files with POST forms and no form file missing `csrfField()`; POST handlers scanned also include server-side token validation. A missing request-image removal token was fixed. Forged-token HTTP rejection still needs a stable live Apache/database session test. |
| PDO / SQL | Partial | PDO native prepares are configured. Static mutation inventory shows parameterized mutation sites; dynamic booking update fields are assembled from an allowlisted status transition. A complete runtime injection suite remains. |
| Authorization / IDOR | Partial | Customer/provider/admin guards are called across their route groups. Booking and review unit suites include ownership rejection cases. All resource paths still need endpoint-level testing. |
| Validation | Partial | Shared validators cover registration, Indian phone numbers, request descriptions/fields, provider profiles, service data/prices, booking schedule/notes, and review rating/text, category names/descriptions, and review moderation notes. They are integrated into the corresponding handlers with browser limits where possible. Further field-by-field negative coverage and remaining forms still need review. |
| Email verification | Added | Six-digit random OTP, hashed storage, 10-minute expiry, five attempts, 60-second resend cooldown, one-time deletion, and unverified account gate. SMTP delivery has not been tested. Development preview requires explicit development environment settings. |
| Audit logs | Partial | Additive table and helper added. Registration, authentication, email verification, provider verification/profile, service, category, request create/update/cancel/image removal, booking create/status/cancel, review create/moderation events are wired. The role-protected audit-log viewer supports action/entity filters and pagination. Broader event coverage and live database verification remain. |
| Soft deletion | Partial | Categories and provider services now deactivate through existing `is_active` flags. User/provider/request/review retention policy and admin historical views remain. |
| PII encryption | Implemented in code; migration pending | AES-256-GCM encrypts provider phone/address and request address on writes. A batch CLI migrator handles existing values, and reads accept legacy plaintext during rollout. Apply phase 14 and configure a private 32-byte base64 key before using non-empty sensitive fields. |
| Upload hardening | Partial | Image type/content/size restrictions exist; random names are used. Apache denies script execution and direct request-image access. `request_image.php` checks customer ownership before serving private request images. Live IDOR/file-delivery tests remain blocked by database access. |
| Database integrity | Partial | Canonical schema has foreign keys, uniqueness, indexes, review rating check, and booking/request links. Canonical reviews schema now includes moderation fields. Live schema migration validation is blocked by MySQL access. |
| Error handling | Partial | Selected handlers log details and return friendly messages; health page no longer displays database exception text or filesystem path. Centralized production error handling remains. |
| UI/UX | Partial | Existing ServeIQ Bootstrap layout and responsive styles are present. Registration now has browser and server validation; broader mobile/accessibility/navigation review remains. |
| Admin provider page | Fixed source mismatch | The page previously selected `average_rating`, `total_reviews`, and `is_verified` columns absent from canonical `provider_profiles`. It now derives reputation from reviews and updates only the existing verification field. |
| Analytics / audit UI | Partial | Admin dashboard now derives booking, assessment, consensus, review, rating, and audit counts from MySQL. A role-protected audit-log page supports action/entity filters, pagination, and empty states. Browser/database verification remains blocked. |
| Marketplace discovery | Added in code; migration pending | Optional provider/request coordinates, response-time estimates with provenance, distance/rating/price/availability filters and sorting, and a synthetic marketplace seed/rollback workflow are present. Apply phase 15 before using the new fields; database-backed demo verification has not run here. |

## Data protection map

- `users.password`: one-way `password_hash()` output; verified using `password_verify()`.
- `provider_profiles.phone`, `provider_profiles.address`, and `service_requests.address`: new writes use versioned AES-256-GCM ciphertext. Existing rows remain plaintext until phase 14 and the CLI migrator have run. These fields are not queried by value.
- `users.email`, names, cities/areas, business/service descriptions, and workflow metadata: ordinary database values needed for account lookup, matching, or product display; they are not covered by application field encryption.
- Business names, service/category names, and published provider/review content: displayed to other users according to existing route access rules and escaped at HTML output sites.

The project does not claim full-database or storage-layer encryption.

## Current migrations

1. `phase11_security_audit.sql`
2. `phase12_login_throttle.sql`
3. `phase13_email_verification.sql`
4. `phase14_sensitive_encryption.sql`
5. `phase15_marketplace_discovery.sql`

Apply each once, in order, to an existing database after checking its current schema. They have not been applied in this environment. After phase 14, run `services/encrypt_existing_pii.php` to protect existing plaintext PII. Phase 15 adds nullable discovery fields and can be rerun safely.

## Executed verification

- PHP syntax check (2026-09-30): 77 files, 0 syntax failures.
- Existing and new standalone test scripts (2026-09-30): 10 scripts, 148 passing cases, 0 failed scripts. See [TESTING.md](TESTING.md) for module coverage and integration gaps.
- XAMPP Apache main configuration check (`httpd.exe -t`): syntax OK. This does not exercise the project `.htaccess` rules through a live HTTP request.
- Earlier local HTTP spot checks: homepage, health page, and login GET returned 200; `/serveiq/.env` and `/serveiq/uploads/` returned 403. The login response exposed only cookie attributes in the inspection: HttpOnly and SameSite=Lax were present, and Secure was absent over local HTTP as configured. HTTPS cookie behavior was not tested. During the 2026-09-30 product pass, Apache on localhost refused connections, so the updated pages were not browser-verified.
- `git diff --check`: no whitespace errors.
- MySQL connection attempt using the configured local root/no-password default: rejected with access denied; database tables and migrations could not be inspected live.
- SMTP: no configured server credentials or successful delivery test; no delivery claim is made.
- Encryption helper tests cover authenticated round-trip, randomized nonce, tamper rejection, legacy plaintext compatibility, and empty values. Existing database rows were not migrated because MySQL access was denied.
