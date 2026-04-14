# Unified Admissions & Student Services Management System - Design Document

## 1. Overview

The system is a fully on-premises, internet-independent platform for managing admissions plans, consultation ticketing, and appointment booking at an education provider. It serves five primary roles: Applicant, Admissions Advisor, Department Manager, Data Steward, and System Administrator.

The architecture follows a client-server model with a Vue.js single-page application consuming a Laravel REST API backed by MySQL.

---

## 2. Architecture

### 2.1 High-Level Stack

| Layer       | Technology                  | Purpose                                      |
|-------------|-----------------------------|----------------------------------------------|
| Frontend    | Vue.js 3.4, Pinia, Vue Router 4 | SPA with role-based views and polling-based updates |
| Backend     | Laravel (PHP 8.2+)         | REST API, workflow orchestration, security    |
| Database    | MySQL 8.0+                  | Relational storage, JSON columns, InnoDB locking |
| Build       | Vite 5.0                    | Frontend asset compilation                   |
| Deployment  | Docker + Nginx + Supervisor | Container-based on-premises deployment       |

### 2.2 Deployment Topology

The application is containerized via Docker Compose. A single Dockerfile provisions PHP-FPM, Nginx (reverse proxy), and Supervisor (process management). The frontend builds into the Laravel `public/build/` directory, served directly by Nginx. No external CDN, cloud service, or internet dependency exists.

### 2.3 Request Flow

```
Browser (Vue SPA)
  │
  ├── Authorization: Bearer <signed-session-token>
  ├── X-Correlation-ID: <uuid>
  │
  ▼
Nginx (reverse proxy)
  │
  ▼
Laravel API (PHP-FPM)
  ├── ForceJsonResponse middleware
  ├── CorrelationId middleware
  ├── VerifySignedSession guard
  ├── RequireMfa middleware
  ├── CheckRole / CheckPermission / CheckContentPermission
  ├── LogOperation middleware
  │
  ▼
Controller → Service → Model → MySQL
```

---

## 3. Authentication & Session Management

### 3.1 Signed Session Tokens

Authentication produces a signed session token rather than a standard Laravel session cookie. The token is a base64-encoded JSON payload containing the token ID, user ID, issued-at, expires-at, and an HMAC-SHA256 signature. The backend `VerifySignedSession` guard decodes, verifies the signature, checks expiry, and validates against the `user_sessions` table on every request.

Tokens are transported via the `Authorization: Bearer` header. The frontend stores the token in `localStorage` and attaches it through an Axios request interceptor.

### 3.2 Login Flow

1. User submits credentials. If CAPTCHA is required (after 5 failed attempts in 15 minutes), the client must include a valid CAPTCHA key and answer.
2. `AuthService.attemptLogin()` validates credentials, checks lockout status, and verifies CAPTCHA if flagged.
3. On success, a session token is issued and returned. If the user has TOTP MFA enabled, the response signals `mfa_required`; the frontend redirects to `/mfa` for verification.
4. On failure, the failed login count increments. After the configured threshold, the account locks for 30 minutes (configurable).
5. A secondary IP-based rate limit of 100 attempts per lockout window defends against distributed brute-force.

### 3.3 Multi-Factor Authentication

TOTP-based MFA is mandatory for admin accounts and optional for other roles. Setup generates a base32 secret, an `otpauth://` URI for QR scanning, and 8 recovery codes in `XXXX-XXXX-XXXX` format. The TOTP secret and recovery codes are encrypted at rest with AES-256-GCM. Verification allows a +/-1 time-step (30-second) window for clock drift. Recovery codes are single-use and bcrypt-hashed.

### 3.4 CAPTCHA

The system uses a server-side math-based CAPTCHA rendered via the GD library. Challenges are stored in `captcha_challenges` with a bcrypt-hashed answer and a 5-minute expiry. This approach requires no internet connectivity.

---

## 4. Authorization

### 4.1 Role-Based Access Control (RBAC)

Five roles map to static permission sets defined in `config/permissions.php`:

| Role       | Key Permissions |
|------------|----------------|
| Applicant  | View published plans, create tickets, book appointments |
| Advisor    | View published plans, reply to assigned tickets, manage appointments |
| Manager    | Full plan lifecycle, ticket routing/reassignment/review, appointment management, reports |
| Steward    | Master data editing, duplicate merge request/approval, reports |
| Admin      | All permissions including security management, audit viewing, sensitive field access |

A user may hold multiple role-scope records simultaneously; permission checks union across all active scopes.

### 4.2 Content-Level Permissions

Beyond role-level permissions, the `CheckContentPermission` middleware enforces entity-type + action scoping (e.g., `masterdata,edit` or `plans,manage`). These are stored as JSON in the `user_role_scopes.content_permissions` column.

### 4.3 Department Scoping

A null `department_scope` on a role-scope record grants global access within that role. A non-null value restricts visibility and actions to that department. Ticket inbox filtering, appointment visibility, and plan access respect this scoping.

---

## 5. Core Modules

### 5.1 Admissions Plans

**Data Model:** An `AdmissionsPlan` is uniquely identified by `academic_year` + `intake_batch`. Each plan has multiple `AdmissionsPlanVersion` records, each containing `AdmissionsPlanProgram` entries with nested `AdmissionsPlanTrack` entries.

**Workflow (Draft-Review-Publish):**

```
draft → submitted → under_review → approved → published
                  ↘ returned → draft (re-enter)
                  ↘ rejected
published → superseded (automatic when new version publishes)
any → archived
```

Each state transition is permission-gated (submit requires `plans.submit_review`, approve requires `plans.approve`, publish requires `plans.publish`). Transitions are recorded in the append-only `plan_state_history` table with actor, timestamp, IP, and before/after hashes.

**Immutability:** Published versions become read-only. The `snapshot_data` JSON column captures the complete plan state at publication time, hashed with SHA-256 for integrity verification. Post-publication edits require creating a new version that re-enters the workflow.

**Version Comparison:** The `compareVersions` service diffs two versions at the program/track/metadata field level, storing results in `plan_version_comparisons`.

### 5.2 Consultation Tickets

**Data Model:** A `ConsultationTicket` has a locally generated number (`TKT-YYYYMMDD-XXXX`), a category tag, a priority (Normal/High), and a status field with nine possible states: `new`, `triaged`, `reassigned`, `in_progress`, `waiting_applicant`, `resolved`, `reopened`, `auto_closed`, `closed`.

**SLA Tracking:** High-priority tickets must receive a first response within 2 business hours; Normal within 1 business day. SLA deadlines are computed at creation time using configurable business hours (default Mon-Fri 08:00-17:00). Overdue tickets are flagged and recalculated every 15 minutes via the `RecalculateSla` command.

**Attachments:** Up to 3 JPEG/PNG files (5 MB each). Files undergo MIME type validation and magic byte signature verification (JPEG: `FF D8 FF`, PNG: `89 50 4E 47`). Mismatched signatures result in quarantine, not silent rejection. Files are stored locally with SHA-256 fingerprints.

**Transcript Immutability:** Consultation messages are append-only; the Eloquent model throws `RuntimeException` on update or delete attempts.

**Quality Review:** The `SampleQualityReview` command selects 5% of closed tickets per advisor per week. Selected tickets are locked to prevent retroactive edits during review. Managers score reviews on a 0-100 scale.

**Reassignment:** Managers can reassign tickets across advisors or departments with a required reason. Each reassignment records a routing history entry.

### 5.3 Appointments

**Data Model:** `AppointmentSlot` defines available time windows with capacity tracking. `Appointment` records bookings with state management across `pending`, `booked`, `rescheduled`, `cancelled`, `completed`, `no_show`, and `expired`.

**Concurrency Control:** Booking uses a database-backed distributed lock (`distributed_locks` table) keyed on the slot ID, plus inventory pre-deduction on the `available_qty` column. A client-generated request key (UUID, 10-minute validity) ensures idempotency — duplicate submissions return the original booking result.

**Policy Rules:**
- Reschedule: Allowed up to 24 hours before start (applicants); no limit for staff with `appointments.override_policy`.
- Cancel: Allowed up to 12 hours before start (applicants); no limit for staff.
- No-show: Marked after 10 minutes past slot start. The slot capacity is not restored.

**Slot Reservation:** The `slot_reservations` table tracks held/confirmed/released/expired capacity with correlation keys and TTL-based expiry.

### 5.4 Master Data Governance

**Entities:** Organizations, Personnel, Positions, and Course Categories. Each supports:
- Coding rules (e.g., `ORG-XXXXXX` for organizations)
- Soft delete (status set to `inactive`, `deleted_at` timestamp)
- Full version history via `master_data_versions` with before/after JSON snapshots and SHA-256 hashes
- Data dictionaries for configurable lookups

**Duplicate Detection:** The `DuplicateDetectionService` identifies potential duplicates by normalized name similarity (confidence 0.85) and employee ID exact match (confidence 0.99). Detected pairs are stored in `duplicate_candidates`.

**Merge Workflow:** Merge requests follow a `proposed → under_review → approved → executed` lifecycle. Execution retires source records with `merged_into_id` pointing to the target; source rows are never physically deleted.

**Data Quality:** Nightly computation of four metrics per entity type:
- **Completeness:** Populated required fields / total required fields
- **Consistency:** Valid cross-references and code formats / total records
- **Uniqueness:** Non-duplicate active records / total active records
- **Timeliness:** Records updated within 90 days / total records

Results are stored for trend reporting in `data_quality_metrics` and `trend_snapshots`.

---

## 6. Security

### 6.1 Encryption at Rest

Sensitive fields (date of birth, government ID, institutional ID, TOTP secrets) are encrypted using AES-256-GCM with a random IV per operation. Encrypted values are stored as base64-encoded JSON containing IV, ciphertext, and authentication tag. The encryption key is read from the `ENCRYPTION_KEY` environment variable as a hex-encoded 32-byte value.

### 6.2 Field Masking

The `MaskingService` applies role-based masking rules in API responses:
- `date_of_birth` → `**/**/****`
- `government_id` → `***-**-{last4}`
- `institutional_id` → `****{last4}`

Users with the `attachments.view_sensitive` permission see unmasked values. CSV exports apply the same masking rules.

### 6.3 Audit Trail

The `audit_log` table is append-only with tamper-evident chain hashing. Each entry's `chain_hash` = SHA-256(previous `chain_hash` | entry data). The genesis hash is the string `"genesis"`. Model-level guards prevent any update or delete on audit records. The `VerifyAuditChain` command and API endpoint validate chain integrity.

### 6.4 Operation Logging

All POST/PUT/PATCH/DELETE requests are logged to `operation_logs` with correlation ID, user, route, method, request summary (sensitive fields redacted), outcome, latency, and IP address. GET requests are excluded to avoid excessive log volume.

---

## 7. Background Jobs

| Job                        | Schedule        | Purpose                                      |
|----------------------------|-----------------|----------------------------------------------|
| RecalculateSla             | Every 15 min    | Recompute SLA deadlines and overdue flags    |
| SampleQualityReview        | Weekly (Mon 01:00) | Select 5% of closed tickets for review    |
| ComputeDataQuality         | Daily (02:00)   | Nightly data quality metrics                 |
| VerifyArtifactIntegrity    | Daily (03:00)   | Verify published plan artifact hashes        |
| CleanupOrphanAttachments   | Daily (04:00)   | Remove orphaned attachment files             |
| ExpirePendingHolds         | Every 5 min     | Expire stale slot reservations               |
| CleanupStaleLocks          | Every 10 min    | Release expired distributed locks            |

All jobs use Laravel's database-backed queue and are designed to be restart-safe and idempotent.

---

## 8. Frontend Architecture

### 8.1 Application Structure

The SPA uses Vue 3 with the Composition API (`<script setup>`), Pinia for state management, and Vue Router 4 for navigation. The Axios HTTP client is configured with interceptors for auth token injection, correlation ID generation, and automatic logout on 401 responses.

### 8.2 Routing & Guards

Routes are classified as guest (login, MFA verify) or protected. The router's `beforeEach` guard enforces authentication, MFA verification, role requirements, and permission requirements before allowing navigation.

### 8.3 State Management

The `useAuthStore` Pinia store manages authentication state (token, user, MFA status, session info) with localStorage persistence. It computes role and permission checks used throughout the application for conditional rendering and navigation guards.

### 8.4 Real-Time Updates

The system uses periodic polling rather than WebSockets for near-real-time updates. The dashboard polls every 10 seconds (configurable via `meta.poll_after_ms`). Ticket detail views poll for new messages and status changes at the same interval.

### 8.5 UI Components

18 page components cover the full feature set: Login, Dashboard, MFA Setup/Verify, Users, User Detail, Tickets, Ticket Detail, Appointments, Admissions Plans, Admissions Plan Detail, Published Plan Detail, Organizations, Personnel, Positions, Course Categories, Dictionaries, and Audit Logs.

Modals follow a consistent pattern: visibility controlled by a reactive ref, form data in a reactive object, API submission on confirm, and form reset on success.

---

## 9. Database Schema Summary

### Core Tables
- `users` - User accounts with encrypted sensitive fields
- `user_role_scopes` - Role assignments with department/entity/content scoping
- `user_sessions` - Signed session token tracking
- `mfa_secrets` - Encrypted TOTP secrets and recovery codes
- `login_attempts` - Login attempt audit trail

### Admissions Tables
- `admissions_plans` - Plan header (year + batch)
- `admissions_plan_versions` - Versioned plan content with state machine
- `admissions_plan_programs` - Programs within a version
- `admissions_plan_tracks` - Tracks within a program
- `plan_state_history` - Append-only state transition log
- `plan_version_comparisons` - Cached version diffs
- `published_artifact_integrity_checks` - Hash verification records

### Consultation Tables
- `consultation_tickets` - Ticket metadata with SLA tracking
- `consultation_messages` - Append-only message transcript
- `consultation_attachments` - File metadata with quarantine support
- `ticket_routing_history` - Reassignment audit trail
- `ticket_quality_reviews` - Sampled review records

### Appointment Tables
- `appointment_slots` - Available time windows with capacity
- `appointments` - Booking records with state machine
- `appointment_state_history` - State transition log
- `slot_reservations` - Capacity reservation tracking
- `distributed_locks` - Database-backed locking

### Master Data Tables
- `organizations`, `personnel`, `positions`, `course_categories` - Entity tables with soft delete
- `master_data_versions` - Version history with snapshots
- `data_dictionaries` - Configurable lookup values
- `duplicate_candidates` - Detected duplicate pairs
- `merge_requests` - Merge workflow tracking

### Quality & Audit Tables
- `audit_log` - Immutable, chain-hashed audit entries
- `operation_logs` - API operation tracking
- `data_quality_runs` - Nightly computation runs
- `data_quality_metrics` - Metric values per entity type
- `trend_snapshots` - Historical metric aggregation

---

## 10. Key Design Decisions

1. **Signed tokens over Laravel sessions** — Enables stateless API authentication suitable for on-premises deployment without shared session storage.
2. **Database-backed distributed locks** — Avoids Redis dependency while still protecting appointment booking concurrency.
3. **Polling over WebSockets** — Simpler deployment (no persistent connection infrastructure) while meeting the "near-real-time" requirement.
4. **Append-only audit with chain hashing** — Provides tamper evidence without requiring external blockchain or write-once storage.
5. **Local CAPTCHA generation** — GD-based math challenges avoid any internet dependency.
6. **Field-level encryption with per-operation IV** — AES-256-GCM with random IV per encryption provides semantic security for sensitive PII fields.
7. **Snapshot-based plan versioning** — Published plans capture their full state as JSON, enabling fast comparison and integrity verification without complex join queries.
8. **Client-generated request keys** — UUID-based idempotency keys with 10-minute TTL prevent double-booking without server-side deduplication infrastructure.
