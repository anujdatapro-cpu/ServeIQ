# ServeIQ demo marketplace

Run these from the project root after the configured MySQL service is running:

```powershell
D:\xampp\php\php.exe -l database\seed_demo_marketplace.php
# Apply database/phase15_marketplace_discovery.sql using the project's configured MySQL client or phpMyAdmin.
# Apply database/phase16_marketplace_status.sql (or use the guarded activation command below).
D:\xampp\php\php.exe database\seed_demo_marketplace.php
D:\xampp\php\php.exe database\test_demo_marketplace.php
D:\xampp\php\php.exe database\phase2_activate_marketplace.php
D:\xampp\php\php.exe database\phase2_activate_marketplace.php --apply
```

The seeder uses the existing nine active categories and adds six focused categories only when missing: washing machine, refrigerator, TV, RO/water purifier, CCTV/security, and tyre/puncture services. These additions distinguish services the existing broad `Appliance Repair` and `Vehicle Repair` categories could not match reliably. It adds only missing demo accounts/services and can be rerun safely. Demo customers are `demo.customer01@serveiq.local` through `demo.customer30@serveiq.local`; providers are `demo.provider001@serveiq.local` through `demo.provider230@serveiq.local`. The development-only password for these synthetic accounts is `ServeIQDemo#2026`.

The seed retains 230 provider accounts to preserve and exercise historical workflow relationships. The Phase 2 activation command chooses exactly 100 approved demo providers across all 15 categories, and marks the remaining profiles inactive for marketplace search without deleting users, services, bookings, or reviews. The activation is preview-only unless `--apply` is supplied. It requires a logical SQL backup of at least 1 MB in `database/backups/` and the included backup is ignored by version control.

Provider phone numbers and addresses are synthetic and encrypted using ServeIQ's existing encryption helper. Provider avatars are generated initials SVGs stored under `uploads/profiles/`. Synthetic published reviews are clearly labelled `[DEMO REVIEW]` and each is linked to a completed demo request, accepted provider response, completed booking, and booking status history. These records exist to exercise existing rating and booking relationships; they are not claims about real customers or service outcomes.

Provider coordinates are approximate Pune locality points. Customer request coordinates are optional and can be captured with the browser's location permission. Radius filtering is available only when the request and provider both have coordinates. Provider response values are explicitly marked `demo_estimate`; estimates entered in a provider profile are marked `provider_estimate` and are not measured response times.

The seed is idempotent. Check what rollback would remove with:

```powershell
D:\xampp\php\php.exe database\rollback_demo_marketplace.php
```

If the preview is clear and you intend to remove the seed:

```powershell
D:\xampp\php\php.exe database\rollback_demo_marketplace.php --confirm=DELETE-DEMO-SEED
```

The seeder now requires a verified-size logical backup under `database/backups/` before it changes data. Rollback aborts when it finds non-demo customer requests or provider workflow data, preserving demo accounts/providers that have since been used for live development. A pre-seed database backup is saved under `database/backups/` and excluded from version control.
