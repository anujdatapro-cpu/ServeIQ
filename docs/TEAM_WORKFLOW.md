# ServeIQ Team Git & Release Workflow

This document defines the standard Git workflow for ServeIQ to prevent merge conflicts, prevent stale branch dependencies, and maintain continuous delivery stability.

---

## 1. Single Source of Truth

- **`origin/main` is the sole production branch and single source of truth.**
- All new feature branches and bug fix branches **must** branch directly off the latest `origin/main`.
- Never reuse or branch off stale developer task branches or closed pull requests.

---

## 2. Standard Branch Creation Strategy

Before starting any task, sync with `origin/main`:

```bash
git fetch origin
git checkout main
git reset --hard origin/main
git checkout -b feature/your-feature-name
```

### Branch Naming Conventions
- Features: `feature/<short-description>` (e.g. `feature/brevo-otp-integration`)
- Fixes: `fix/<short-description>` (e.g. `fix/navbar-scroll-margin`)
- Docs / Refactor: `docs/<description>` or `refactor/<description>`

---

## 3. Keeping Branches Up to Date

If `origin/main` advances while you are working on your feature branch, merge or rebase `origin/main` into your feature branch before opening or updating a Pull Request:

```bash
git fetch origin
git merge origin/main
```

---

## 4. Conflict Resolution Guidelines

When resolving merge conflicts:
1. **Never use blanket conflict rules** (e.g. blindly accepting `--ours` or `--theirs`).
2. Inspect every conflicting block (`<<<<<<<`, `=======`, `>>>>>>>`).
3. Retain legitimate functionality from both sides (e.g. preserve latest UI improvements alongside updated backend service parameters).
4. Verify that zero conflict markers remain in the entire codebase:
   ```bash
   grep -rn "<<<<<<<" .
   ```

---

## 5. Mandatory Pre-Merge Automated Checks

Before submitting a Pull Request, execute the mandatory verification checks:

### A. PHP Syntax Verification
```bash
php -l config/email.php
php -l config/load_environment.php
php -l services/BrevoEmailService.php
php -l services/ResendEmailService.php
```

### B. Unit & Integration Test Suite
Run the full 11-suite test runner in `services/`:
```bash
php services/email_security_test.php
php services/booking_test.php
php services/matching_test.php
php services/service_dna_test.php
php services/service_dna_variants_test.php
php services/adcs_test.php
php services/ai_test.php
php services/encryption_test.php
php services/review_test.php
php services/security_helpers_test.php
php services/session_security_test.php
```

---

## 6. Pull Request Protocol

1. Open Pull Requests **strictly into `main`** as the target branch.
2. Verify that PR checks pass and all 11 test suites pass with 100%.
3. Do not force-push shared published branches (`origin/main`).
4. Ensure `.env` is never committed (keep untracked in `.gitignore`).
