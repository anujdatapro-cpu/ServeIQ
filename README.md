# ServeIQ

> Describe Your Problem. Find the Right Service.

ServeIQ is an AI-assisted local service marketplace. A customer describes a real-world problem in natural language, receives a structured problem fingerprint, and can compare suitable local service providers.

## 1. Project Overview

ServeIQ is being developed as a modular college project using PHP, MySQL, Bootstrap, and browser-native JavaScript. The current foundation contains the public homepage, database schema, shared layout, and local/Docker environment configuration.

## 2. Problem Statement

Finding help for a broken device, appliance, vehicle, or household system usually requires repeated calls, repeated explanations, and manual quotation comparisons.

## 3. Solution

ServeIQ captures the problem once, organizes its important details, and provides a foundation for matching customers with relevant service providers and comparing their responses.

## 4. Key Features

- Natural-language problem submission
- ServiceDNA problem fingerprinting
- Local provider matching
- Provider diagnosis and quotation comparison
- Role-based customer, provider, and admin workflows
- Secure PDO database access

## 5. Core Innovation

**ServiceDNA** transforms a natural-language service problem into a structured problem fingerprint containing symptoms, urgency, possible causes, and service context.

**ADCS (Adaptive Diagnostic Consensus System)** is the planned extension that compares independent provider diagnoses and quotations. Advanced AI features are under development. ServeIQ must not make unsupported claims about diagnostic accuracy; all early results are recommendations or rule-based analysis.

## 6. Technology Stack

- PHP 8.2+
- MySQL 8
- HTML5, CSS3, JavaScript
- Bootstrap 5 and Bootstrap Icons
- PDO with native prepared statements
- Apache through XAMPP, Laragon, or Docker

## 7. Project Architecture

The application uses a straightforward PHP structure. Shared presentation files live under `includes/`, database access is isolated in `config/`, business services are kept under `services/`, and SQL is maintained in `database/serveiq.sql`.

## 8. Installation Instructions

### XAMPP or Laragon

1. Copy the repository into XAMPP `htdocs` or Laragon `www`.
2. Start Apache and MySQL.
3. Import `database/serveiq.sql` into MySQL.
4. Copy `.env.example` to `.env` only if you want to override the local defaults. The current PHP application also works without `.env`.
5. Open the project folder URL configured in Apache, for example `http://localhost/<project-folder>/`.

## 9. Database Setup

Import `database/serveiq.sql` using phpMyAdmin or the MySQL client. The script creates `serveiq_db`, all current tables, indexes, foreign keys, and starter service categories. See [docs/DATABASE_GUIDELINES.md](docs/DATABASE_GUIDELINES.md) before changing the schema.

## 10. Environment Configuration

Copy `.env.example` to `.env` for local overrides. The project currently reads environment variables through PHP's built-in `getenv()` and does not require Composer or a dotenv package. Never commit `.env`.

## 11. Running Locally

The default local values are `127.0.0.1`, port `3306`, database `serveiq_db`, user `root`, and an empty password. Change them in `.env` or your server environment when needed.

## 12. Team Collaboration

Use `develop` for integration and work in short-lived `feature/*` or `fix/*` branches. Read [docs/TEAM_WORKFLOW.md](docs/TEAM_WORKFLOW.md) for branch, commit, pull request, and conflict guidance.

## 13. Docker Support

Docker is an optional alternative to XAMPP/Laragon. Run `docker compose up --build` and open `http://localhost:8080`. The web container uses `DB_HOST=serveiq-db`; local Apache continues to use `DB_HOST=127.0.0.1` by default. See [docs/DOCKER_GUIDE.md](docs/DOCKER_GUIDE.md).

## 14. Project Structure

```text
serveiq/
├── assets/              # CSS, JavaScript, and image assets
├── config/              # Environment-aware PHP configuration
├── database/            # Canonical database schema
├── docs/                # Team and infrastructure documentation
├── includes/            # Shared PHP layout and guards
├── services/            # Domain service classes, added by later phases
├── uploads/             # Runtime user uploads, ignored by Git
├── Dockerfile
├── docker-compose.yml
└── index.php
```

## 15. Screenshots

Screenshots will be added here after the first complete user workflow is implemented.

## 16. Future Enhancements

- Authentication and role-based authorization
- Customer, provider, and admin dashboards
- Rule-based ServiceDNA engine
- Provider matching and consensus analysis
- Secure image upload/download workflows
- Leaflet/OpenStreetMap location support
- Optional external AI integration with transparent confidence handling

## 17. Contributors

Add team member names and responsibilities here as the group is finalized.

## GitHub initialization

```bash
git init
git add .
git commit -m "Initial commit: ServeIQ project setup"
git remote add origin REPOSITORY_URL
git branch -M main
git push -u origin main
```

Team members can clone and create a branch with:

```bash
git clone REPOSITORY_URL
cd serveiq
git checkout develop
git checkout -b feature/your-feature
```
