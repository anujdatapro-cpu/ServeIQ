# Coding Standards

## PHP

- Use meaningful variable and function names.
- Use PDO prepared statements for database input.
- Validate input on the server.
- Escape output with the correct HTML context.
- Keep business logic in services where practical.
- Keep shared layout and authorization checks in `includes/`.

## Database

- Use `snake_case` table and column names.
- Use meaningful foreign-key constraint names.
- Add `created_at` and `updated_at` to mutable records.
- Update `database/serveiq.sql` with every structural change.

## Frontend

- Prefer reusable CSS classes over inline styles.
- Avoid duplicate JavaScript behavior.
- Keep interactions accessible on keyboard and small screens.
- Preserve the existing Bootstrap and Bootstrap Icons conventions.

## Git

- Make small, meaningful commits.
- Use imperative, scoped commit messages.
- Review `git diff` before committing.
- Never commit `.env`, credentials, generated uploads, or unrelated formatting changes.

Examples: `feat: add user authentication`, `fix: resolve login session issue`, `docs: update installation guide`.