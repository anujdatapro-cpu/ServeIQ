# ServeIQ Test Record

This record distinguishes isolated automated checks from live application workflows. A passing helper test does not establish that MySQL, SMTP, Apache, or a browser flow works.

## Automated checks run

Run date: 2026-09-30. PHP CLI: `D:\xampp\php\php.exe`.

| Test IDs | Module | Scenario coverage | Expected result | Actual result |
|---|---|---|---|---|
| PHP-001 | PHP syntax | Lint every project PHP file | No parse errors | PASS, 77 files |
| JS-001 | Client-side JavaScript | Parse `assets/js/main.js` with Node.js | No syntax errors | PASS, `node --check` |
| UI-CLI-001 | Landing page rendering | Render `index.php` under PHP CLI with database unavailable | Product sections and graceful category fallback render | PASS, expected content present; this does not replace an HTTP/browser check |
| DNA-001–003 | ServiceDNA | Laptop overheating, AC cooling issue, empty description | Rule-based fields are derived; empty input has zero confidence | PASS, 3/3 |
| AI-001–026 | Enhancement/fallback | Disabled enhancement, malformed result, timeout/exception, agreement/disagreement, PII redaction, follow-up questions, matching and ADCS regression | Invalid/unavailable enhancement falls back safely; expected fields and bounds remain | PASS, 26/26 |
| MATCH-001–006 | Provider matching | Category/service/location and unclassified problem fixtures | Relevant fixtures pass threshold; unclassified fixture stays below threshold | PASS, 6/6 |
| ADCS-001–006 | ADCS | Full/partial consensus, outlier, one/zero assessments, conflicting assessments | Status, score bounds, assessment count, and outlier count match fixtures | PASS, 6/6 |
| BOOK-001–040 | Booking | Allowed/blocked status transitions, role rules, schedule and ownership cases | Invalid transitions and unauthorized operations are rejected | PASS, script exit 0 (40 case lines) |
| REV-001–015 | Reviews | Completed-booking eligibility, ownership, duplicate review, rating boundaries, aggregation | Only eligible unique reviews are accepted; values and reputation are computed as expected | PASS, 15/15 |
| SEC-001–035 | Security helpers and validators | Registration/email/phone/password, request text, price/rating/date, role, CSRF, throttle keys, category length and moderation-note boundaries | Valid input accepted; invalid, missing, and over-limit input rejected | PASS, 35/35 |
| ENC-001–006 | Encryption | Authenticated round trip, randomized nonce, tampering, legacy text, empty value | Ciphertext authenticates and invalid/tampered content is rejected | PASS, 6/6 |
| SES-001–009 | Session security | Cookie attributes, strict mode, rotation, inactivity boundary, teardown | Configured protections and expiry behavior match expectations | PASS, 9/9 |
| MAIL-001–002 | Email security helpers | OTP/email delivery security helper cases | Security helper behavior matches assertions | PASS, 2/2 |

Total: 148 case lines across 10 standalone scripts; all scripts exited with code 0. The checks are isolated PHP tests and do not connect to the project database or send email.

## Integration checks not completed

| Test ID | Module | Scenario | Expected result | Actual result |
|---|---|---|---|---|
| DB-001 | Database | Connect using project environment settings | `SELECT 1` succeeds | NOT RUN in this pass; prior project audit recorded MySQL access denied |
| PREVIEW-001 | ServiceDNA preview endpoint | Submit a valid CSRF-protected description and receive database-backed rule analysis | Real ServiceDNA fields returned without persisting the preview | NOT RUN over HTTP; Apache was unavailable and MySQL access is not configured |
| DB-002 | Schema/migrations | Apply phases 11–14 to a known existing schema | Migrations apply once in order without data loss | NOT RUN; requires a database backup and working MySQL connection |
| AUTH-001 | Registration/OTP | Register customer and provider, deliver, expire, resend, and consume OTP | Correctly verified accounts proceed; invalid/expired OTPs are rejected | NOT RUN end to end; SMTP and live DB are not configured |
| WEB-001 | HTTP/browser | Load homepage, role navigation, forms, and responsive layouts | Pages render and workflows remain usable at desktop/mobile widths | NOT RUN; Apache on `localhost` refused connections during this pass |
| AUTHZ-001 | RBAC/IDOR | Attempt cross-customer and cross-provider resource access over HTTP | Requests are denied without leaking private data | Existing helper coverage only; endpoint-level HTTP test NOT RUN |
| CSRF-001 | CSRF | Submit forged token to a state-changing HTTP route | Server rejects the request | Helper coverage only; endpoint-level HTTP test NOT RUN |
| UPLOAD-001 | Uploads | Upload valid and invalid files and attempt traversal/direct private-image access | Only allowed images are stored/served to authorized owners | NOT RUN end to end |

## Re-running

```powershell
$phpPath = 'D:\xampp\php\php.exe'
Get-ChildItem -Recurse -File -Filter '*.php' | ForEach-Object { & $phpPath -l $_.FullName }
Get-ChildItem services -File -Filter '*_test.php' | ForEach-Object { & $phpPath $_.FullName }
```

For HTTP/browser checks, start Apache and MySQL, configure SMTP when testing email delivery, apply only the migrations appropriate to the current schema, then exercise role-specific accounts and viewport sizes. Record those outcomes separately from these helper tests.
