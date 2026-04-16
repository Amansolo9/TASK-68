# Unified Admissions & Student Services Management System

> **Project type:** Fullstack (Laravel API + Vue.js SPA)

On-premises admissions planning, consultation handling, appointment booking, master data governance, and operational oversight platform built with **Laravel 11**, **Vue.js 3**, and **MySQL 8**.

Zero internet dependency. Runs entirely on-prem.

---

## Quick Start

```bash
docker-compose up --build -d
```

The app is available at **http://localhost:8000** once the container reports ready (~30 seconds for first-run migrations and seeding).

### Default Accounts

| Username    | Password              | Role               |
|-------------|-----------------------|--------------------|
| admin       | AdminPassword123!     | System Administrator |
| manager     | ManagerPassword123!   | Department Manager |
| advisor     | AdvisorPassword123!   | Admissions Advisor |
| steward     | StewardPassword123!   | Data Steward       |
| applicant   | ApplicantPassword123! | Applicant          |

> Change all passwords immediately after first login.

### Verify the system is running

```bash
# 1. Health check — should return JSON with session info or 401
curl -s http://localhost:8000/api/auth/session | head -c 200

# 2. Login and get a token
curl -s -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"applicant","password":"ApplicantPassword123!"}' | head -c 300

# 3. Confirm the UI loads
curl -s -o /dev/null -w "%{http_code}" http://localhost:8000
# Expected: 200
```

If all three return responses (401 for unauthenticated session, 200 with token for login, 200 for UI), the system is operational.

---

## Project Structure

```
repo/
├── backend/                       # Laravel 11 API server
│   ├── app/
│   │   ├── Console/Commands/      # 8 artisan commands (SLA, metrics, integrity…)
│   │   ├── Http/
│   │   │   ├── Controllers/Api/   # 16 REST controllers
│   │   │   └── Middleware/        # 9 middleware (auth, MFA, RBAC, logging…)
│   │   ├── Models/                # 33 Eloquent models
│   │   ├── Policies/              # 2 policies (Appointment, Ticket)
│   │   ├── Providers/
│   │   ├── Services/              # 16 services (auth, audit, booking, SLA…)
│   │   └── Traits/
│   ├── config/                    # App, auth, permissions, security, etc.
│   ├── database/
│   │   ├── migrations/            # 25 migrations
│   │   └── seeders/               # Demo data seeder
│   ├── routes/api.php             # 103 API endpoints
│   ├── tests/
│   │   ├── unit_tests/            # 7 PHPUnit unit test files
│   │   └── api_tests/             # 13 PHPUnit feature/API test files
│   ├── artisan
│   ├── composer.json
│   └── phpunit.xml
│
├── frontend/                      # Vue.js 3 SPA
│   ├── src/
│   │   ├── components/            # AppLayout (role-aware sidebar)
│   │   ├── pages/                 # 18 page components
│   │   ├── router/                # Vue Router with auth/MFA/permission guards
│   │   ├── store/                 # Pinia auth store with permission model
│   │   ├── utils/                 # Axios API client with token injection
│   │   └── css/
│   ├── tests/
│   │   ├── unit_tests/            # 9 Vitest component/logic test files
│   │   └── e2e/                   # 7 Playwright end-to-end test files
│   ├── package.json
│   ├── vite.config.js
│   └── playwright.config.js
│
├── docker/                        # nginx, supervisor, php.ini, entrypoint
├── Dockerfile                     # Multi-stage: node build + composer + php-fpm
├── docker-compose.yml             # app + mysql services
├── run_tests.sh                   # Unified test runner (all/backend/frontend/unit/api/e2e)
├── .env.example
├── ASSUMPTIONS.md                 # 51 documented implementation assumptions
└── DEPLOYMENT.md                  # On-prem deployment guide + operational runbook
```

---

## Architecture

### Tech Stack

| Layer            | Technology                                    |
|------------------|-----------------------------------------------|
| Frontend         | Vue.js 3, Vue Router 4, Pinia, Axios          |
| Backend API      | Laravel 11 (PHP 8.2)                          |
| Database         | MySQL 8.0                                     |
| Authentication   | Signed session tokens (HMAC-SHA256)            |
| MFA              | Offline TOTP (RFC 6238) for administrators     |
| File Storage     | Local disk with SHA-256 fingerprinting         |
| Background Jobs  | Laravel scheduler + database-backed queue      |
| Containerization | Docker multi-stage build, nginx + php-fpm      |

### System Modules

1. **Authentication & Security** — signed tokens, TOTP MFA, CAPTCHA, rate limiting, field-level encryption
2. **Admissions Plans** — versioned plans with draft/review/publish lifecycle, immutable published snapshots, version comparison
3. **Consultation Tickets** — applicant tickets with attachments, SLA timers, triage inbox, quality-review sampling
4. **Appointment Booking** — slot inventory, idempotent booking with request keys, concurrency-safe with distributed locks
5. **Master Data Governance** — organizations, personnel, positions, course categories with version history, duplicate detection, steward-approved merge
6. **Data Quality & Reporting** — nightly metric computation, trend dashboards, CSV export with masking

---

## Roles & Permissions

| Capability                    | Applicant | Advisor | Manager | Steward | Admin |
|-------------------------------|:---------:|:-------:|:-------:|:-------:|:-----:|
| Browse published plans        |     x     |    x    |    x    |    x    |   x   |
| Create/manage plan versions   |           |         |    x    |         |   x   |
| Approve/publish plans         |           |         |    x    |         |   x   |
| Submit consultation tickets   |     x     |         |         |         |       |
| Triage/reply to tickets       |           |    x    |    x    |         |   x   |
| Reassign tickets              |           |         |    x    |         |   x   |
| Book appointments             |     x     |         |         |         |       |
| Manage appointment slots      |           |    x    |    x    |         |   x   |
| Edit master data              |           |         |         |    x    |   x   |
| Approve merges                |           |         |         |    x    |   x   |
| Manage users / security       |           |         |         |         |   x   |
| View audit logs               |           |         |         |         |   x   |
| View reports / exports        |           |         |    x    |    x    |   x   |

Authorization enforced at three layers:
- **Route middleware** — role-level permission checks
- **Content middleware** — section/entity-level RBAC
- **Policies** — object-level ownership and department scope (appointments, tickets)

---

## Security Controls

- **Signed session tokens** — HMAC-SHA256 with server-side secret; no client-side session state
- **Offline TOTP MFA** — mandatory for admin accounts; enforced server-side on all protected routes
- **Rate limiting** — per-IP throttle on login; CAPTCHA required after 5 failed attempts per username
- **Offline CAPTCHA** — server-generated math challenges via GD library; no external service
- **Field encryption** — DOB, government IDs, TOTP secrets encrypted at rest with AES-256-GCM
- **Field masking** — sensitive fields masked in API/UI responses based on role
- **Append-only audit log** — tamper-evident chain hashing; every state transition records actor, timestamp, IP, before/after hashes
- **Operation logging** — all mutating API requests logged with masked payloads
- **Attachment validation** — JPEG/PNG only, MIME + magic byte verification, SHA-256 fingerprint, quarantine for mismatches

---

## API Overview

All endpoints return the standard envelope:

```json
{
    "data": { },
    "meta": { "correlation_id": "uuid", "poll_after_ms": 10000 },
    "error": null
}
```

### Key Endpoint Groups

| Prefix                | Auth  | Description                              |
|-----------------------|-------|------------------------------------------|
| `POST /api/auth/login`| None  | Login with username/password              |
| `POST /api/auth/captcha` | None | Generate offline CAPTCHA challenge     |
| `/api/auth/*`         | Token | Session, logout, refresh                  |
| `/api/mfa/*`          | Token | TOTP setup, verify, recovery codes        |
| `/api/published-plans`| Token + MFA | Published plans (all roles)         |
| `/api/admissions-plans` | Token + MFA + Permission | Internal plan management |
| `/api/tickets`        | Token + MFA | Consultation tickets (scoped by role) |
| `/api/appointments`   | Token + MFA | Booking, reschedule, cancel, no-show  |
| `/api/users`          | Token + MFA + Admin | User management             |
| `/api/audit-logs`     | Token + MFA + Admin | Audit log viewer            |
| `/api/reports/*`      | Token + MFA + Permission | Operational reporting    |
| `/api/duplicates`     | Token + MFA + Steward | Duplicate detection        |
| `/api/merge-requests` | Token + MFA + Steward | Merge workflow             |

---

## Running Tests

### Unified test runner

```bash
./run_tests.sh              # All tests (backend + frontend + e2e)
./run_tests.sh backend      # Backend unit + API tests only
./run_tests.sh frontend     # Frontend Vitest unit tests only
./run_tests.sh e2e          # Playwright end-to-end tests only
./run_tests.sh unit         # Unit tests only (backend + frontend)
./run_tests.sh api          # Backend API/integration tests only
```

The script is self-sufficient — only Docker is required on the host:
- Backend tests always run inside a `composer:2` Docker container (in-memory SQLite) — no local PHP or Composer needed
- Frontend deps install via `node:20-alpine` Docker container when `node_modules/` is missing
- Frontend unit and E2E tests use local Node.js if available, or Docker fallback
- E2E auto-starts the Docker stack (`docker-compose up`) if the app is not running
- Clears rate-limit data before E2E to prevent throttling
- No manual `composer install` or `npm install` is ever required — the script handles everything via Docker

### Test Counts

| Suite              | Files | Tests | Framework       |
|--------------------|------:|------:|-----------------|
| Backend unit       |     7 |    55 | PHPUnit 11      |
| Backend API        |    13 |   175 | PHPUnit 11      |
| Frontend unit      |     9 |    68 | Vitest 1.6      |
| Frontend e2e       |     7 |    41 | Playwright 1.44 |
| **Total**          | **36**| **339**|                |

### Running tests manually

```bash
# Backend (from backend/)
cd backend
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpunit --testsuite API
vendor/bin/phpunit --filter=AdmissionsPlanTest

# Frontend unit (from frontend/)
cd frontend
npx vitest run
npx vitest run tests/unit_tests/Appointments.spec.js

# E2E (from frontend/, requires running app)
cd frontend
npx playwright test
npx playwright test --headed     # see the browser
npx playwright test --ui         # interactive UI mode
```

---

## State Machines

### Admissions Plan Version

```
draft → submitted → under_review → approved → published → superseded
                  ↘ returned → draft         ↗
                  ↘ rejected → archived
```

Published versions are immutable. Post-publication edits create a new draft derived from the published snapshot.

### Consultation Ticket

```
new → triaged → in_progress → resolved → closed
              → reassigned → triaged
              → waiting_applicant → in_progress / auto_closed
     resolved → reopened → triaged
```

SLA timers: High = 2 business hours, Normal = 1 business day.

### Appointment

```
pending → booked → rescheduled → booked
                 → cancelled
                 → completed
                 → no_show (slot consumed, not restored)
```

Reschedule: up to 24h before start. Cancel: up to 12h before start. No-show: 10 min after start.

### Merge Request

```
proposed → under_review → approved → executed
                        → rejected
         → cancelled
```

Steward approval required. Source records retired (not deleted), linked to target.

---

## Background Jobs

| Command                              | Schedule         | Purpose                          |
|--------------------------------------|------------------|----------------------------------|
| `tickets:recalculate-sla`            | Every 15 min     | Flag overdue tickets             |
| `tickets:sample-quality-review`      | Mondays 01:00    | 5% weekly quality-review sample  |
| `metrics:compute-data-quality`       | Daily 02:00      | Nightly completeness/consistency/uniqueness/timeliness |
| `artifacts:verify-integrity`         | Daily 03:00      | Hash verification of published plans |
| `attachments:cleanup-orphans`        | Daily 04:00      | Remove failed upload artifacts   |
| `appointments:expire-pending-holds`  | Every 5 min      | Release expired slot reservations |
| `locks:cleanup-stale`                | Every 10 min     | Clear expired distributed locks  |
| `audit:verify-chain`                 | Manual           | Verify tamper-evident audit chain |

---

## Configuration

All configuration is via environment variables. See `.env.example` for the full list.

| Variable                    | Default          | Description                     |
|-----------------------------|------------------|---------------------------------|
| `APP_KEY`                   | auto-generated   | Laravel encryption key           |
| `SESSION_TOKEN_SECRET`      | (required)       | HMAC secret for signed tokens    |
| `ENCRYPTION_KEY`            | (required)       | AES-256-GCM key (64 hex chars)   |
| `DB_HOST` / `DB_DATABASE`   | mysql / admissions | MySQL connection               |
| `MFA_REQUIRED_FOR_ADMIN`    | true             | Enforce TOTP for admin role      |
| `LOGIN_MAX_ATTEMPTS`        | 5                | Failed logins before CAPTCHA     |
| `SLA_HIGH_PRIORITY_HOURS`   | 2                | High-priority first-response SLA |
| `BUSINESS_HOURS_START/END`  | 08:00 / 17:00   | SLA business hours               |
| `POLL_INTERVAL_MS`          | 10000            | Client polling interval (ms)     |

---

## Docker Details

The `Dockerfile` uses a three-stage build:

1. **`node:20-alpine`** — builds Vue.js frontend with Vite
2. **`composer:2`** — installs PHP dependencies
3. **`php:8.2-fpm-alpine`** — production image with nginx, supervisor, cron

The `docker-compose.yml` defines two services:
- **`mysql`** — MySQL 8.0 with health check
- **`app`** — the application (nginx + php-fpm + queue worker + scheduler)

The entrypoint script:
1. Creates storage directories
2. Generates `APP_KEY` if not provided
3. Writes `.env` from environment variables
4. Waits for MySQL readiness
5. Runs migrations
6. Seeds demo data on first run (empty database)
7. Caches config/routes/views
8. Starts supervisor (nginx, php-fpm, queue worker, cron)

### Useful commands

```bash
# Start
docker-compose up --build -d

# View logs
docker logs -f repo-app-1

# Shell into container
docker exec -it repo-app-1 sh

# Run artisan commands
docker exec repo-app-1 php artisan audit:verify-chain
docker exec repo-app-1 php artisan metrics:compute-data-quality

# Rebuild after code changes
docker-compose up --build -d

# Full reset (wipes data)
docker-compose down -v && docker-compose up --build -d
```

---

## On-Premises Deployment

See [DEPLOYMENT.md](DEPLOYMENT.md) for:
- Apache/Nginx configuration
- Supervisor setup for queue workers
- Crontab setup for scheduler
- Security checklist
- Operational runbook (audit verification, user unlock, password reset, backups)

---

## Assumptions

See Docs/ for documented implementation decisions covering:
- Authentication & token format
- CAPTCHA implementation
- Encryption algorithms
- SLA business hours
- Duplicate detection strategy
- Data quality metric formulas
- Deployment requirements
