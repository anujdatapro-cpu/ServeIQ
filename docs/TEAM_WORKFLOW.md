# Team Workflow

## Branch strategy

```text
main
└── develop
    ├── feature/*
    └── fix/*
```

- `main` contains stable, production-ready code only.
- `develop` is the integration branch for completed features.
- `feature/*` is for one feature or focused work item.
- `fix/*` is for bug fixes.
- Do not push experimental or incomplete work directly to `main`.

## Start working

```bash
git clone REPOSITORY_URL
cd serveiq
git checkout develop
git pull origin develop
git checkout -b feature/authentication
```

Use names such as `feature/authentication`, `feature/customer-dashboard`, `feature/provider-dashboard`, `feature/service-management`, `feature/problem-fingerprint`, `feature/provider-matching`, `feature/admin-panel`, `fix/login-error`, `fix/database-connection`, and `docs/readme-update`.

## Commit and push

Keep commits small and focused. Use messages such as:

```text
feat: add user authentication
feat: create provider dashboard
fix: resolve login session issue
docs: update installation guide
style: improve homepage responsiveness
```

```bash
git add path/to/changed/files
git commit -m "feat: describe the change"
git push -u origin feature/authentication
```

## Pull requests

1. Update your branch from `develop`.
2. Run the available local checks.
3. Open a pull request into `develop`.
4. Explain the change, testing performed, and any database changes.
5. Request review from at least one teammate.
6. Merge only after review and conflict resolution.

## Avoiding conflicts

Coordinate ownership before editing shared files such as `database/serveiq.sql`, `config/database.php`, `includes/navbar.php`, and `assets/css/style.css`. Prefer separate files and small commits. Pull `develop` before beginning work and before opening a pull request.

## Resolving conflicts

Before switching branches, confirm the worktree is clean or commit the work you intend to keep. Use the PR's actual base branch (`main` or `develop`), not an assumed default.

```bash
git fetch origin
git status --short --branch
git branch -vv
git switch feature/your-feature
git merge origin/<pr-base>
```

Inspect both sides of each conflict and preserve the intended behavior from both. After resolving, check the index and diff before committing:

```bash
git ls-files -u
git diff --check
git diff
git add path/to/resolved-file
git diff --cached --check
git diff --cached
git commit -m "chore: resolve merge conflicts"
git push origin feature/your-feature
```

Do not use force-push or destructive reset/clean commands to hide a conflict or discard a teammate's work. Keep `.env` and local database/runtime files out of commits.