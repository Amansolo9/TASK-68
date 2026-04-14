# Unified Admissions & Student Services Management System - API Specification

## Base URL

```
/api
```

## Common Headers

| Header              | Required | Description                                    |
|---------------------|----------|------------------------------------------------|
| `Authorization`     | Yes*     | `Bearer <signed-session-token>` (* except public routes) |
| `Content-Type`      | Yes      | `application/json`                             |
| `Accept`            | Yes      | `application/json`                             |
| `X-Correlation-ID`  | Auto     | UUID generated per request for tracing         |

## Response Envelope

All responses follow a standard envelope:

```json
{
  "data": { ... },
  "meta": {
    "correlation_id": "uuid",
    "pagination": {
      "current_page": 1,
      "per_page": 20,
      "total": 100,
      "last_page": 5
    },
    "poll_after_ms": 10000
  },
  "error": null
}
```

Error responses:

```json
{
  "data": null,
  "meta": { "correlation_id": "uuid" },
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "The given data was invalid.",
    "details": { "field": ["Error message"] }
  }
}
```

## Pagination

List endpoints accept:
- `page` (integer, default 1)
- `per_page` (integer, default 20; audit logs and dictionaries default to 50)

---

## 1. Authentication

### POST /auth/login

Authenticate user and obtain a signed session token.

**Middleware:** LoginThrottle

**Request Body:**

| Field           | Type   | Required | Description                               |
|-----------------|--------|----------|-------------------------------------------|
| `username`      | string | Yes      | User's login name                         |
| `password`      | string | Yes      | User's password                           |
| `captcha_key`   | string | Cond.    | Required after 5 failed attempts in 15min |
| `captcha_answer` | string | Cond.   | Answer to CAPTCHA challenge               |

**Response (200):**

```json
{
  "data": {
    "token": "base64-encoded-signed-token",
    "user": {
      "id": 1,
      "username": "advisor",
      "full_name": "Advisor User",
      "roles": ["advisor"],
      "mfa_required": false
    },
    "mfa_required": false
  }
}
```

**Error Responses:**
- `401` — Invalid credentials
- `423` — Account locked (includes `lockout_until`)
- `422` — CAPTCHA required or invalid

---

### POST /auth/logout

Revoke the current session token.

**Middleware:** auth:signed_session

**Response (200):**

```json
{ "data": { "message": "Logged out successfully" } }
```

---

### GET /auth/session

Retrieve current session details.

**Middleware:** auth:signed_session

**Response (200):**

```json
{
  "data": {
    "user": { "id": 1, "username": "admin", "full_name": "Admin User", "roles": ["admin"] },
    "session": {
      "issued_at": "2024-01-15T08:00:00Z",
      "expires_at": "2024-01-15T16:00:00Z",
      "mfa_verified": true
    }
  }
}
```

---

### POST /auth/refresh

Refresh (extend) the current session token.

**Middleware:** auth:signed_session

**Response (200):**

```json
{ "data": { "token": "new-base64-encoded-signed-token" } }
```

---

### POST /auth/captcha

Generate a new CAPTCHA challenge.

**Response (200):**

```json
{ "data": { "captcha_key": "unique-challenge-key", "expires_at": "2024-01-15T08:05:00Z" } }
```

---

### GET /auth/captcha/{key}

Retrieve CAPTCHA image as PNG.

**Response:** `image/png` binary content

---

## 2. Multi-Factor Authentication

### POST /mfa/setup

Initialize TOTP MFA setup for the current user.

**Middleware:** auth:signed_session

**Response (200):**

```json
{
  "data": {
    "secret": "BASE32ENCODEDSECRET",
    "otpauth_uri": "otpauth://totp/App:username?secret=...",
    "recovery_codes": ["XXXX-XXXX-XXXX", ...]
  }
}
```

---

### POST /mfa/verify

Verify TOTP code and activate MFA.

**Middleware:** auth:signed_session

**Request Body:**

| Field  | Type   | Required | Description       |
|--------|--------|----------|-------------------|
| `code` | string | Yes      | 6-digit TOTP code |

**Response (200):**

```json
{ "data": { "message": "MFA enabled successfully", "mfa_verified": true } }
```

---

### POST /mfa/verify-login

Verify TOTP during login flow (marks session as MFA-verified).

**Middleware:** auth:signed_session

**Request Body:**

| Field  | Type   | Required | Description       |
|--------|--------|----------|-------------------|
| `code` | string | Yes      | 6-digit TOTP code |

**Response (200):**

```json
{ "data": { "message": "MFA verified", "mfa_verified": true } }
```

---

### POST /mfa/recovery/use

Use a recovery code instead of TOTP.

**Middleware:** auth:signed_session

**Request Body:**

| Field           | Type   | Required | Description              |
|-----------------|--------|----------|--------------------------|
| `recovery_code` | string | Yes      | One-time recovery code   |

**Response (200):**

```json
{ "data": { "message": "Recovery code accepted", "remaining_codes": 7 } }
```

---

### POST /mfa/disable

Admin action: disable MFA for a user.

**Middleware:** auth:signed_session, require_mfa, permission:security.manage

**Request Body:**

| Field     | Type    | Required | Description          |
|-----------|---------|----------|----------------------|
| `user_id` | integer | Yes      | Target user ID       |

---

### POST /mfa/recovery/generate

Admin action: regenerate recovery codes for a user.

**Middleware:** auth:signed_session, require_mfa, permission:security.manage

**Request Body:**

| Field     | Type    | Required | Description    |
|-----------|---------|----------|----------------|
| `user_id` | integer | Yes      | Target user ID |

---

## 3. User Management

### GET /users

List users with filtering.

**Middleware:** auth:signed_session, require_mfa, log_operation, permission:security.manage

**Query Parameters:**

| Param    | Type   | Description                    |
|----------|--------|--------------------------------|
| `search` | string | Search by username or full name |
| `status` | string | Filter: active, inactive, locked |
| `role`   | string | Filter by role                 |
| `page`   | int    | Page number                    |

**Response (200):**

```json
{
  "data": [
    {
      "id": 1,
      "username": "admin",
      "full_name": "Admin User",
      "email": "admin@example.com",
      "status": "active",
      "roles": [{ "role": "admin", "department_scope": null }],
      "totp_enabled": true,
      "last_login_at": "2024-01-15T08:00:00Z"
    }
  ],
  "meta": { "pagination": { ... } }
}
```

---

### POST /users

Create a new user.

**Request Body:**

| Field                  | Type   | Required | Description                 |
|------------------------|--------|----------|-----------------------------|
| `username`             | string | Yes      | Unique login name           |
| `password`             | string | Yes      | Min 12 characters           |
| `full_name`            | string | Yes      | Display name                |
| `email`                | string | No       | Email address               |
| `date_of_birth`        | string | No       | ISO 8601 date (encrypted)   |
| `government_id`        | string | No       | Government ID (encrypted)   |
| `institutional_id`     | string | No       | Institutional ID (encrypted)|
| `department_id`        | string | No       | Department assignment       |
| `roles`                | array  | Yes      | Array of role-scope objects  |
| `roles[].role`         | string | Yes      | Role name                   |
| `roles[].department_scope` | string | No  | Department scope (null=global) |

---

### GET /users/{id}

Get user detail with role scopes.

### PUT /users/{id}

Update user information.

### POST /users/{id}/deactivate

Deactivate user and revoke all sessions.

### POST /users/{id}/activate

Reactivate an inactive user.

### POST /users/{id}/unlock

Unlock a locked account (resets failed login count).

### POST /users/{id}/reset-password

Reset user password and revoke all sessions.

**Request Body:**

| Field          | Type   | Required | Description       |
|----------------|--------|----------|-------------------|
| `new_password` | string | Yes      | Min 12 characters |

### PUT /users/{id}/roles

Update user role assignments.

**Request Body:**

| Field   | Type  | Required | Description              |
|---------|-------|----------|--------------------------|
| `roles` | array | Yes      | Array of role-scope objects |

---

## 4. Admissions Plans (Published - All Authenticated Users)

### GET /published-plans

List published admissions plans.

**Middleware:** auth:signed_session, require_mfa, log_operation

**Query Parameters:**

| Param           | Type   | Description         |
|-----------------|--------|---------------------|
| `academic_year` | string | Filter by year      |
| `intake_batch`  | string | Filter by batch     |
| `page`          | int    | Page number         |

**Response (200):**

```json
{
  "data": [
    {
      "id": 1,
      "academic_year": "2026",
      "intake_batch": "Fall 2026",
      "status": "active",
      "published_version": {
        "id": 3,
        "version_no": 2,
        "state": "published",
        "effective_date": "2026-01-15",
        "published_at": "2024-01-10T12:00:00Z",
        "published_by": 2,
        "programs": [
          {
            "program_code": "CS-BSC",
            "program_name": "Computer Science BSc",
            "planned_capacity": 120,
            "tracks": [
              {
                "track_code": "CS-AI",
                "track_name": "Artificial Intelligence",
                "planned_capacity": 40,
                "admission_criteria": "Math prerequisite required"
              }
            ]
          }
        ]
      }
    }
  ]
}
```

---

### GET /published-plans/{admissionsPlan}

Get full detail of a published plan including all programs and tracks.

---

## 5. Admissions Plans (Staff Management)

### GET /admissions-plans

List all plans (internal staff view).

**Middleware:** permission:plans.create_version, content.permission:plans,manage

### POST /admissions-plans

Create a new admissions plan with an initial draft version.

**Request Body:**

| Field           | Type   | Required | Description                  |
|-----------------|--------|----------|------------------------------|
| `academic_year` | string | Yes      | e.g., "2026"                 |
| `intake_batch`  | string | Yes      | e.g., "Fall 2026"            |
| `description`   | string | No       | Plan description             |
| `effective_date` | date  | No       | Target effective date        |

---

### GET /admissions-plans/{id}

Get plan with all versions and current version detail.

### PUT /admissions-plans/{id}

Update plan metadata.

---

### POST /admissions-plans/{id}/versions

Create a new draft version.

**Request Body:**

| Field             | Type    | Required | Description                    |
|-------------------|---------|----------|--------------------------------|
| `derive_from`     | integer | No       | Version ID to copy content from |
| `description`     | string  | No       | Version description            |
| `effective_date`  | date    | No       | Target effective date          |

---

### POST /admissions-plans/{id}/derive-from-published

Create a new draft version derived from the latest published version.

---

### GET /admissions-plans/{id}/versions/{version}

Get version detail with programs, tracks, and state history.

### PUT /admissions-plans/{id}/versions/{version}

Update version metadata (draft state only).

---

### POST /admissions-plans/{id}/versions/{version}/programs

Add a program to a version.

**Request Body:**

| Field             | Type    | Required | Description          |
|-------------------|---------|----------|----------------------|
| `program_code`    | string  | Yes      | Unique within version |
| `program_name`    | string  | Yes      | Display name         |
| `description`     | string  | No       | Program description  |
| `planned_capacity`| integer | No       | Expected enrollment  |
| `capacity_notes`  | string  | No       | Capacity notes       |

### PUT /admissions-plans/{id}/versions/{version}/programs/{program}

Update a program.

### DELETE /admissions-plans/{id}/versions/{version}/programs/{program}

Remove a program (draft only).

---

### POST /admissions-plans/{id}/versions/{version}/programs/{program}/tracks

Add a track to a program.

**Request Body:**

| Field               | Type    | Required | Description            |
|---------------------|---------|----------|------------------------|
| `track_code`        | string  | Yes      | Unique within program  |
| `track_name`        | string  | Yes      | Display name           |
| `description`       | string  | No       | Track description      |
| `planned_capacity`  | integer | No       | Expected enrollment    |
| `capacity_notes`    | string  | No       | Capacity notes         |
| `admission_criteria`| string  | No       | Entry requirements     |

### PUT /admissions-plans/{id}/versions/{version}/programs/{program}/tracks/{track}

Update a track.

### DELETE /admissions-plans/{id}/versions/{version}/programs/{program}/tracks/{track}

Remove a track (draft only).

---

### POST /admissions-plans/{id}/versions/{version}/transition

Transition a version's workflow state.

**Request Body:**

| Field        | Type   | Required | Description                                    |
|--------------|--------|----------|------------------------------------------------|
| `transition` | string | Yes      | Target state: submitted, under_review, returned, approved, rejected, published, archived |
| `reason`     | string | Cond.    | Required for returned, rejected, archived      |

**State Machine:**

```
draft → submitted → under_review → approved → published
                  ↘ returned → draft
                  ↘ rejected
published → superseded (automatic)
any → archived
```

**Permission by Transition:**
- submitted, under_review: `plans.submit_review`
- returned, approved, rejected: `plans.approve`
- published: `plans.publish`
- archived, draft: `plans.create_version`

---

### POST /admissions-plans/{id}/compare

Compare two plan versions.

**Request Body:**

| Field              | Type    | Required | Description   |
|--------------------|---------|----------|---------------|
| `left_version_id`  | integer | Yes      | First version  |
| `right_version_id` | integer | Yes      | Second version |

**Response (200):**

```json
{
  "data": {
    "comparison": {
      "metadata_changes": { ... },
      "programs_added": [ ... ],
      "programs_removed": [ ... ],
      "programs_modified": [ ... ],
      "tracks_added": [ ... ],
      "tracks_removed": [ ... ],
      "tracks_modified": [ ... ]
    }
  }
}
```

---

### GET /admissions-plans/{id}/versions/{version}/integrity

Verify the integrity of a published version's snapshot hash.

---

## 6. Consultation Tickets

### POST /tickets

Create a new consultation ticket.

**Middleware:** permission:tickets.create, content.permission:tickets,view

**Request Body (multipart/form-data):**

| Field          | Type     | Required | Description                        |
|----------------|----------|----------|------------------------------------|
| `category_tag` | string   | Yes      | From data dictionary (e.g., GENERAL, ADMISSION) |
| `priority`     | string   | Yes      | Normal or High                     |
| `message`      | string   | Yes      | Initial consultation message       |
| `attachments[]`| file     | No       | Up to 3 files, JPEG/PNG, max 5MB each |

**Response (201):**

```json
{
  "data": {
    "id": 42,
    "local_ticket_no": "TKT-20240115-0001",
    "status": "new",
    "priority": "High",
    "category_tag": "ADMISSION",
    "first_response_due_at": "2024-01-15T10:00:00Z",
    "created_at": "2024-01-15T08:00:00Z"
  }
}
```

---

### GET /tickets

List tickets (role-scoped).

**Query Parameters:**

| Param        | Type   | Description                         |
|--------------|--------|-------------------------------------|
| `status`     | string | Filter by status                    |
| `priority`   | string | Filter: Normal, High                |
| `department`  | string | Filter by department                |
| `category`   | string | Filter by category tag              |
| `overdue`    | bool   | Filter overdue tickets only         |
| `search`     | string | Search ticket number or message     |
| `page`       | int    | Page number                         |

**Scoping Rules:**
- Applicant: sees own tickets only
- Advisor: sees tickets assigned to them
- Manager: sees tickets in their department scope
- Admin: sees all tickets

---

### GET /tickets/{id}

Get ticket detail with messages, attachments, and routing history.

**Response (200):**

```json
{
  "data": {
    "id": 42,
    "local_ticket_no": "TKT-20240115-0001",
    "status": "in_progress",
    "priority": "High",
    "category_tag": "ADMISSION",
    "applicant": { "id": 5, "full_name": "Applicant User" },
    "advisor": { "id": 3, "full_name": "Advisor User" },
    "first_response_due_at": "2024-01-15T10:00:00Z",
    "overdue_flag": false,
    "messages": [
      {
        "id": 1,
        "sender": { "id": 5, "full_name": "Applicant User" },
        "message_text": "Question about Fall 2026 admissions...",
        "created_at": "2024-01-15T08:00:00Z"
      }
    ],
    "attachments": [
      {
        "id": 1,
        "original_filename": "transcript.jpg",
        "mime_type": "image/jpeg",
        "file_size": 245760,
        "sha256_fingerprint": "abc123...",
        "upload_status": "completed"
      }
    ],
    "routing_history": [
      {
        "from_advisor": "Advisor A",
        "to_advisor": "Advisor B",
        "reason": "Specialization match",
        "actor": "Manager User",
        "created_at": "2024-01-15T09:00:00Z"
      }
    ]
  }
}
```

---

### GET /tickets/{id}/poll

Lightweight poll for ticket updates.

**Response (200):**

```json
{
  "data": {
    "message_count": 5,
    "status": "in_progress",
    "last_updated_at": "2024-01-15T09:30:00Z"
  },
  "meta": { "poll_after_ms": 10000 }
}
```

---

### POST /tickets/{id}/reply

Add a reply to the ticket conversation.

**Request Body:**

| Field     | Type   | Required | Description    |
|-----------|--------|----------|----------------|
| `message` | string | Yes      | Reply text     |

**Error:** `423` if ticket is locked for quality review.

---

### POST /tickets/{id}/transition

Change ticket status.

**Middleware:** permission:tickets.reply_assigned

**Request Body:**

| Field    | Type   | Required | Description         |
|----------|--------|----------|---------------------|
| `status` | string | Yes      | New status value    |
| `reason` | string | Cond.    | Required for some transitions |

---

### POST /tickets/{id}/reassign

Reassign ticket to a different advisor or department.

**Middleware:** permission:tickets.reassign

**Request Body:**

| Field           | Type    | Required | Description             |
|-----------------|---------|----------|-------------------------|
| `advisor_id`    | integer | No       | New advisor (if changing) |
| `department_id` | string  | No       | New department           |
| `reason`        | string  | Yes      | Reassignment reason (min 5 chars) |

---

### GET /quality-reviews

List quality review samples.

**Middleware:** permission:tickets.review_sampled

### PUT /quality-reviews/{id}

Update a quality review.

**Request Body:**

| Field          | Type    | Required | Description        |
|----------------|---------|----------|--------------------|
| `review_state` | string  | Yes      | in_review, completed |
| `score`        | integer | Cond.    | 0-100 (for completed) |
| `notes`        | string  | No       | Review notes       |

---

## 7. Appointments

### GET /slots

List available appointment slots.

**Middleware:** permission:appointments.manage

**Query Parameters:**

| Param       | Type   | Description        |
|-------------|--------|--------------------|
| `from_date` | date   | Start date filter  |
| `to_date`   | date   | End date filter    |
| `type`      | string | Slot type filter   |

---

### POST /slots

Create an appointment slot.

**Middleware:** permission:appointments.manage

**Request Body:**

| Field        | Type    | Required | Description                          |
|--------------|---------|----------|--------------------------------------|
| `slot_type`  | string  | Yes      | IN_PERSON, PHONE, or VIDEO           |
| `start_at`   | datetime| Yes      | Slot start time                      |
| `end_at`     | datetime| Yes      | Slot end time                        |
| `capacity`   | integer | Yes      | Number of concurrent appointments    |
| `department_id`| string| No       | Department assignment                |
| `advisor_id` | integer | No       | Advisor assignment                   |

---

### GET /appointments/my

List the current user's own appointments.

**Middleware:** permission:appointments.book

---

### POST /appointments/book

Book an appointment.

**Middleware:** permission:appointments.book

**Request Body:**

| Field          | Type    | Required | Description                         |
|----------------|---------|----------|-------------------------------------|
| `slot_id`      | integer | Yes      | Target slot                         |
| `booking_type` | string  | Yes      | IN_PERSON, PHONE, or VIDEO          |
| `request_key`  | string  | Yes      | Client-generated UUID (idempotency) |

**Response (201):**

```json
{
  "data": {
    "id": 10,
    "slot_id": 5,
    "state": "booked",
    "booking_type": "IN_PERSON",
    "booked_at": "2024-01-15T08:00:00Z",
    "slot": {
      "start_at": "2024-01-20T10:00:00Z",
      "end_at": "2024-01-20T10:30:00Z"
    }
  }
}
```

**Error Responses:**
- `409` — Lock contention (retry)
- `422` — Slot full or request key expired
- `200` — Duplicate request key returns original booking

---

### GET /appointments

List appointments (staff view).

**Middleware:** permission:appointments.manage

**Scoping:**
- Advisor: sees own department appointments
- Manager/Admin: sees all appointments

---

### POST /appointments/{id}/reschedule

Reschedule an appointment.

**Request Body:**

| Field         | Type    | Required | Description                  |
|---------------|---------|----------|------------------------------|
| `new_slot_id` | integer | Yes      | Target slot for reschedule   |
| `reason`      | string  | Yes      | Reschedule reason            |
| `request_key` | string  | Yes      | New client-generated UUID    |

**Policy:** Applicants: 24 hours before start. Staff with `appointments.override_policy`: no limit.

---

### POST /appointments/{id}/cancel

Cancel an appointment.

**Request Body:**

| Field    | Type   | Required | Description         |
|----------|--------|----------|---------------------|
| `reason` | string | Yes      | Cancellation reason |

**Policy:** Applicants: 12 hours before start. Staff with `appointments.override_policy`: no limit.

---

### GET /appointments/{id}

Get appointment detail with state history.

### POST /appointments/{id}/no-show

Mark appointment as no-show (staff only, after 10 min past slot start).

### POST /appointments/{id}/complete

Mark appointment as completed (staff only).

---

## 8. Master Data

### Organizations

| Method | Endpoint              | Permission      | Description        |
|--------|-----------------------|-----------------|--------------------|
| GET    | /organizations        | authenticated   | List organizations |
| POST   | /organizations        | masterdata.edit | Create             |
| GET    | /organizations/{id}   | authenticated   | Get detail         |
| PUT    | /organizations/{id}   | masterdata.edit | Update             |
| DELETE | /organizations/{id}   | masterdata.edit | Soft delete        |

**Create/Update Fields:**

| Field           | Type   | Required | Validation                  |
|-----------------|--------|----------|-----------------------------|
| `code`          | string | Yes      | Format: `ORG-XXXXXX`       |
| `name`          | string | Yes      | Organization name           |
| `type`          | string | Yes      | From data dictionary        |
| `address`       | string | No       | Physical address            |
| `phone`         | string | No       | Contact phone               |
| `parent_org_id` | integer| No       | Parent organization FK      |

### Personnel

| Method | Endpoint           | Permission      | Description     |
|--------|--------------------|-----------------|-----------------|
| GET    | /personnel         | authenticated   | List personnel  |
| POST   | /personnel         | masterdata.edit | Create          |
| GET    | /personnel/{id}    | authenticated   | Get detail      |
| PUT    | /personnel/{id}    | masterdata.edit | Update          |
| DELETE | /personnel/{id}    | masterdata.edit | Soft delete     |

**Create/Update Fields:**

| Field            | Type   | Required | Description              |
|------------------|--------|----------|--------------------------|
| `employee_id`    | string | Yes      | Unique employee ID       |
| `full_name`      | string | Yes      | Full name                |
| `email`          | string | No       | Email address            |
| `phone`          | string | No       | Phone number             |
| `date_of_birth`  | string | No       | ISO 8601 (encrypted)     |
| `government_id`  | string | No       | Government ID (encrypted)|
| `organization_id`| integer| No       | Organization FK          |

### Positions

| Method | Endpoint          | Permission      | Description    |
|--------|-------------------|-----------------|----------------|
| GET    | /positions        | authenticated   | List positions |
| POST   | /positions        | masterdata.edit | Create         |
| GET    | /positions/{id}   | authenticated   | Get detail     |
| PUT    | /positions/{id}   | masterdata.edit | Update         |
| DELETE | /positions/{id}   | masterdata.edit | Soft delete    |

### Course Categories

| Method | Endpoint                | Permission      | Description       |
|--------|-------------------------|-----------------|-------------------|
| GET    | /course-categories      | authenticated   | List categories   |
| POST   | /course-categories      | masterdata.edit | Create            |
| GET    | /course-categories/{id} | authenticated   | Get detail        |
| PUT    | /course-categories/{id} | masterdata.edit | Update            |
| DELETE | /course-categories/{id} | masterdata.edit | Soft delete       |

### Data Dictionaries

| Method | Endpoint                  | Permission      | Description        |
|--------|---------------------------|-----------------|--------------------|
| GET    | /lookup/dictionaries/{type}| authenticated  | Public lookup      |
| GET    | /dictionaries             | masterdata.edit | List all entries   |
| POST   | /dictionaries             | masterdata.edit | Create entry       |
| GET    | /dictionaries/{id}        | masterdata.edit | Get entry          |
| PUT    | /dictionaries/{id}        | masterdata.edit | Update entry       |
| DELETE | /dictionaries/{id}        | masterdata.edit | Soft delete        |

---

## 9. Duplicate Detection & Merge

### GET /duplicates

List detected duplicate candidates.

**Middleware:** permission:masterdata.merge_request, content.permission:masterdata,merge

### POST /duplicates/detect

Run duplicate detection.

**Request Body:**

| Field         | Type   | Required | Description                    |
|---------------|--------|----------|--------------------------------|
| `entity_type` | string | Yes      | "personnel" or "organization"  |

### PUT /duplicates/{id}

Update duplicate candidate status (confirm/reject).

### GET /merge-requests

List merge requests.

### POST /merge-requests

Create a merge request.

**Request Body:**

| Field              | Type    | Required | Description                 |
|--------------------|---------|----------|-----------------------------|
| `entity_type`      | string  | Yes      | "personnel" or "organization"|
| `source_entity_ids`| array   | Yes      | IDs of records to merge     |
| `target_entity_id` | integer | Yes      | Surviving record ID         |
| `reason`           | string  | Yes      | Merge justification         |

### POST /merge-requests/{id}/transition

Transition merge request state.

**Middleware:** permission:masterdata.merge_approve

**Request Body:**

| Field    | Type   | Required | Description                                 |
|----------|--------|----------|---------------------------------------------|
| `status` | string | Yes      | under_review, approved, rejected, cancelled |
| `reason` | string | Cond.    | Required for rejection                      |

### POST /merge-requests/{id}/execute

Execute an approved merge.

**Middleware:** permission:masterdata.merge_approve

---

## 10. Audit & Reporting

### GET /audit-logs

List audit log entries.

**Middleware:** permission:audit.view

**Query Parameters:**

| Param         | Type   | Description           |
|---------------|--------|-----------------------|
| `entity_type` | string | Filter by entity type |
| `event_type`  | string | Filter by event       |
| `actor_id`    | int    | Filter by actor       |
| `from_date`   | date   | Start date            |
| `to_date`     | date   | End date              |
| `page`        | int    | Page number           |

### GET /audit-logs/{id}

Get audit log entry detail.

### GET /audit-logs/verify/chain

Verify audit chain integrity.

**Query Parameters:**

| Param     | Type | Description                   |
|-----------|------|-------------------------------|
| `from_id` | int  | Starting entry for verification |

**Response (200):**

```json
{
  "data": {
    "valid": true,
    "entries_checked": 1500,
    "first_broken_at": null
  }
}
```

---

### GET /dashboard

Dashboard overview data.

### GET /dashboard/poll

Lightweight polling endpoint for near-real-time updates.

---

### Reporting Endpoints

All require `permission:reports.view` and `content.permission:reports,view`.

| Endpoint                    | Description                    |
|-----------------------------|--------------------------------|
| GET /reports/tickets        | Ticket statistics by status, priority, category, overdue, SLA attainment |
| GET /reports/appointments   | Appointment statistics: total, booked, completed, no-show rate |
| GET /reports/plans          | Plan version counts by state   |
| GET /reports/data-quality   | Latest data quality run metrics |
| GET /reports/data-quality/trend | Historical data quality trends |
| GET /reports/merges         | Merge request statistics       |
| GET /reports/export         | CSV export                     |

### GET /reports/export

Export data as CSV.

**Query Parameters:**

| Param  | Type   | Required | Description                                  |
|--------|--------|----------|----------------------------------------------|
| `type` | string | Yes      | tickets, appointments, plans, or data_quality |

**Response:** `text/csv` with appropriate `Content-Disposition` header. Sensitive fields are masked based on the requesting user's permissions.
