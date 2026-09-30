# Docker Guide

Docker is an optional development environment. XAMPP and Laragon remain supported and use the local database defaults in `config/database.php`.

## Prerequisites

- Docker Desktop with Docker Compose v2
- Git

## Start ServeIQ

From the project root:

```bash
docker compose up --build
```

Open [http://localhost:8080](http://localhost:8080).

The `serveiq-web` container runs Apache and PHP 8.2. The `serveiq-db` container runs MySQL 8. The web application connects to `serveiq-db` over the internal `serveiq-network`, while the database is also available on host port `3307` for inspection tools.

On its first start, MySQL imports the canonical `database/serveiq.sql` file. Existing database volumes are not initialized again.

## Stop the stack

```bash
docker compose down
```

## Stop and remove data volumes

```bash
docker compose down -v
```

This removes the MySQL data and uploaded-file volumes. Use it only when a clean database and upload directory are intended.

## Rebuild after changes

```bash
docker compose up --build
```

The source tree is bind-mounted into the web container for development. Rebuild when changing the Dockerfile, PHP extensions, or PHP upload settings. MySQL data and uploads persist in named volumes.

## Connection details

Inside Docker, the application uses:

```text
DB_HOST=serveiq-db
DB_PORT=3306
DB_NAME=serveiq_db
DB_USER=serveiq_user
```

From XAMPP/Laragon, the default is `DB_HOST=127.0.0.1` and port `3306`. Docker's host port `3307` is only for host-side database tools, not for the web container.
