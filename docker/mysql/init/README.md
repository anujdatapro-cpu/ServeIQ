# MySQL initialization

The Compose service mounts the canonical `database/` directory directly into MySQL's initialization directory. Keep `database/serveiq.sql` as the single source of schema truth; do not create a second copy here.