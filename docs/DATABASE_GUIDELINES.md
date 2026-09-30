# Database Guidelines

- `database/serveiq.sql` is the canonical schema for the project.
- Never manually change a shared or production schema without updating the SQL file in the same change.
- Document every new table, column, index, and relationship in the pull request.
- Use `snake_case` names consistently.
- Prefer foreign keys and explicit indexes for relationship columns.
- Keep timestamps on mutable business records.
- Avoid duplicate schema versions or undocumented SQL files.
- Coordinate changes to `database/serveiq.sql` because it is a shared integration file.
- Test a fresh import after structural changes.