# Unified Admissions & Student Services Management System - Clarification Questions

## 1. Signed Session Token Format and Transport

**Question:** The prompt requires "API authentication via signed session tokens" but does not specify the token structure, signature algorithm, or transport mechanism. Should tokens follow an existing standard (e.g., JWT) or use a custom format? How should the client transmit the token on each request?

**My Understanding:** A custom signed token is more appropriate than JWT for an offline system since JWT libraries often pull in external dependencies. The token should be compact, self-contained for basic validation, and verifiable against a server-side record to support revocation.

**Solution:** The implementation in `backend/app/Services/SessionTokenService.php` produces a base64-encoded JSON payload containing `token_id`, `user_id`, `issued_at`, `expires_at`, and an HMAC-SHA256 `signature` computed over the other fields using the application key. The `VerifySignedSession` guard in `backend/app/Services/Guards/SignedSessionGuard.php` decodes the token, verifies the signature, checks expiry, and validates the token against the `user_sessions` table. Tokens are transported via the `Authorization: Bearer` header, with a query-parameter fallback (`?_token=`) supported but discouraged. The frontend Axios interceptor in `frontend/src/utils/api.js` attaches the header automatically.

---

## 2. CAPTCHA Implementation for Offline Operation

**Question:** The prompt requires CAPTCHA after 5 failed logins in 15 minutes but the system must function "without any internet dependency." All major CAPTCHA providers (reCAPTCHA, hCaptcha, Turnstile) require internet connectivity. What CAPTCHA mechanism should be used?

**My Understanding:** A server-side generated challenge is the only viable approach for a fully offline system. A math-based or text-based challenge rendered as a server-generated image avoids any external service dependency while still deterring automated credential stuffing.

**Solution:** The `CaptchaService` in `backend/app/Services/CaptchaService.php` generates math-based challenges (addition of two random integers) and renders them as PNG images via PHP's GD library. Challenges are stored in the `captcha_challenges` table with a bcrypt-hashed answer and a 5-minute expiry. The `CaptchaController` exposes generation and image retrieval endpoints. The `LoginThrottle` middleware in `backend/app/Http/Middleware/LoginThrottle.php` triggers the CAPTCHA requirement after the configured failure threshold. Challenge images include noise lines and character distortion to resist OCR.

---

## 3. Encryption Key Management Without Remote Secret Managers

**Question:** The prompt specifies that "sensitive fields (DOB, IDs) are encrypted at rest" and the system must operate "without any internet dependency," which rules out cloud-based key management services (AWS KMS, Azure Key Vault, HashiCorp Vault SaaS). How should the encryption key be provisioned and protected?

**My Understanding:** For an on-premises system, the encryption key must be managed locally. Environment variable injection is the standard approach for containerized deployments — the key is set at deployment time and never stored in source control or application configuration files.

**Solution:** The `EncryptionService` in `backend/app/Services/EncryptionService.php` reads the key from the `ENCRYPTION_KEY` environment variable as a hex-encoded 32-byte value. The service uses AES-256-GCM with a random IV per encryption operation, storing the result as base64-encoded JSON containing IV, ciphertext, and authentication tag. The `backend/config/security.php` file references `env('ENCRYPTION_KEY')` and defines which fields (`date_of_birth`, `government_id`, `institutional_id`, `totp_secret`) are encrypted. Key rotation would require a migration script to re-encrypt existing records, which is not yet implemented.

---

## 4. Password Hashing Algorithm Selection

**Question:** The prompt requires security best practices for credential storage but does not name a specific hashing algorithm. Different algorithms (bcrypt, Argon2id, scrypt) have different trade-offs for on-premises hardware.

**My Understanding:** Bcrypt is the safest default choice — it is battle-tested, natively supported by Laravel's `Hash::make()`, has no external library requirements, and its adaptive cost factor scales with hardware. Argon2id would be preferable on modern hardware but requires the PHP `sodium` extension which may not be available in all on-premises environments.

**Solution:** The implementation uses bcrypt via Laravel's built-in `Hash::make()` with the default adaptive cost factor. This is applied in `AuthController.php` for login verification, `UserController.php` for password creation and resets, and `CaptchaService.php` for challenge answer hashing.

---

## 5. Audit Log Tamper Evidence Mechanism

**Question:** The prompt requires "every state transition writes an append-only audit entry with actor, timestamp, IP, and before/after hashes" and mentions "content integrity checks." How should tamper evidence be achieved without external infrastructure like a blockchain or write-once storage?

**My Understanding:** A cryptographic hash chain provides tamper evidence: each audit entry includes a hash computed over its own data plus the previous entry's hash. Any modification to a historical entry breaks the chain from that point forward, making tampering detectable during verification.

**Solution:** The `AuditService` in `backend/app/Services/AuditService.php` computes `chain_hash = SHA-256(previous_chain_hash | entry_data)` for each new audit entry, with the genesis hash set to the literal string `"genesis"`. The `AuditLog` model in `backend/app/Models/AuditLog.php` enforces immutability at the Eloquent level — `updating()` and `deleting()` boot callbacks throw `RuntimeException`. Chain integrity is verifiable through the `VerifyAuditChain` command and the `GET /api/audit-logs/verify/chain` endpoint, which walks the chain from a given starting point and reports any broken links.

---

## 6. Ticket Numbering Strategy for Immediate Local Assignment

**Question:** The prompt requires that ticket submission "confirms submission with a local ticket number" immediately. In a concurrent environment, how should ticket numbers be generated to guarantee uniqueness without contention, while remaining human-readable?

**My Understanding:** A date-partitioned sequential format balances readability with concurrency: daily counters reset the sequence while keeping numbers short. Database-level uniqueness constraints prevent collisions even under concurrent inserts.

**Solution:** The `TicketService` in `backend/app/Services/TicketService.php` generates ticket numbers in the format `TKT-YYYYMMDD-XXXX`, where `XXXX` is a zero-padded sequential number per day. The `generateTicketNumber()` method queries the max existing sequence for the current date and increments it. The `local_ticket_no` column has a unique index on the `consultation_tickets` table, ensuring database-level collision protection.

---

## 7. SLA Deadline Computation Across Business Hours

**Question:** The prompt specifies "High priority tickets must receive a first response within 2 business hours, Normal within 1 business day" but does not define what constitutes business hours or how the deadline is calculated when a ticket is submitted outside business hours. Should holidays be accounted for?

**My Understanding:** Business hours should be configurable with sensible defaults. Tickets submitted outside business hours should have their SLA clock start at the next business hour opening. A holiday calendar is desirable but complex to pre-populate; the initial implementation should support configurable business-day rules with holidays as a future enhancement.

**Solution:** The `backend/config/sla.php` file defines `business_hour_start` (default 08:00), `business_hour_end` (default 17:00), and `business_days` (default Mon-Fri, represented as `[1,2,3,4,5]`). The `TicketService.computeSlaDeadline()` method calculates the `first_response_due_at` timestamp by advancing only through business hours. The `RecalculateSla` artisan command runs every 15 minutes to recompute overdue flags. No holiday calendar is pre-loaded; the system supports only business-day rules. All SLA configuration values are overridable via environment variables.

---

## 8. Appointment Booking Concurrency and Idempotency

**Question:** The prompt requires "database-backed distributed locks plus inventory pre-deduction on time slots, guaranteeing idempotency by requiring a client-generated request key valid for 10 minutes." What specific locking mechanism should be used, and how should duplicate submissions be detected?

**My Understanding:** A dedicated locks table with key uniqueness, owner tracking, and TTL-based expiry provides distributed locking without Redis. The client-generated request key should be stored with the appointment record; on duplicate submission, the system matches the key and returns the original result rather than creating a new booking.

**Solution:** The `distributed_locks` table (migration `2024_04_01_000001`) stores `lock_key`, `owner`, `acquired_at`, and `expires_at`. The `LockService.withLock()` method acquires a row-level lock keyed on the slot ID, executes the booking logic within a database transaction, and releases the lock afterward. Lock contention returns a 409 Conflict response. The `AppointmentService.book()` method checks for an existing appointment with the same `request_key`; if found within the 10-minute validity window, it returns the existing booking. The `CleanupStaleLocks` command runs every 10 minutes to release expired locks. Capacity is pre-deducted from `appointment_slots.available_qty` within the transaction.

---

## 9. Attachment Security: MIME Validation vs. File Signature Verification

**Question:** The prompt requires "image files are stored locally on disk with SHA-256 fingerprints and strict MIME/type checks to prevent tampering." Should validation rely solely on the declared MIME type (which can be spoofed), or should the actual file content be inspected?

**My Understanding:** Declared MIME types are trivially spoofable and should never be trusted alone. The file's magic bytes (file signature) must be verified against the declared type. Files that fail this check should be quarantined rather than silently dropped, preserving evidence for security review.

**Solution:** The `TicketService.validateAttachmentList()` and `processAttachment()` methods in `backend/app/Services/TicketService.php` perform multi-layer validation: (1) check the declared MIME type is in the allowlist (JPEG, PNG), (2) read the file's first bytes and verify magic byte signatures (JPEG: `FF D8 FF`, PNG: `89 50 4E 47`), (3) enforce a 5 MB size limit, and (4) compute a SHA-256 fingerprint of the file contents. Files with mismatched MIME type vs. actual signature are stored with `upload_status = 'quarantined'` and a `quarantine_reason`, rather than being rejected outright. This preserves the file for security investigation while preventing it from being served to users.

---

## 10. Published Plan Immutability and Post-Publication Edits

**Question:** The prompt states "published plans become read-only and are traceable to the approving manager and effective date" and that "post-publication edits require a new version that re-enters submit-review-publish." How should the system enforce read-only status on published versions, and how should the new version inherit content from the published one?

**My Understanding:** Immutability should be enforced at both the application and data levels. Published versions should capture a snapshot of their complete state to prevent indirect modification through related records. Creating a new version from a published one should deep-copy all programs and tracks.

**Solution:** The `PlanVersionService.transitionState()` method in `backend/app/Services/PlanVersionService.php` captures a full JSON snapshot of the version (including all programs and tracks) in the `snapshot_data` column at publication time, along with a SHA-256 `snapshot_hash`. The `handlePublish()` method also records `published_by`, `published_at`, and creates an integrity check record in `published_artifact_integrity_checks`. The `updateVersion()` method in `backend/app/Http/Controllers/Api/AdmissionsPlanController.php` rejects modifications to any version not in `draft` state. When a new version is published, the `handlePublish()` method automatically transitions any previously published version to `superseded` status, ensuring only one published version exists per plan. The `deriveFromPublished()` method deep-copies programs and tracks from the latest published version into a new draft.

---

## 11. Quality Review Sampling and Transcript Lock Semantics

**Question:** The prompt requires "quality-review sampling automatically selects 5% of closed tickets per advisor per week for manager review with a lock to prevent retroactive edits." What exactly does "lock" mean here — should the ticket be locked from all edits, or only from edits by the advisor being reviewed? When is the lock applied and released?

**My Understanding:** The lock should prevent any modifications to the ticket's conversation transcript once it has been selected for quality review. This ensures the manager reviews the actual conversation as it occurred, not a sanitized version. The lock should be applied at selection time and remain until the review is completed.

**Solution:** The `SampleQualityReview` command (running weekly on Monday at 01:00) selects 5% of closed tickets per advisor and creates `ticket_quality_reviews` records with `locked_at` set to the current timestamp. The `TicketService.addReply()` method checks whether the ticket has an active quality review lock before allowing new messages — if `locked_at` is set and `review_state` is not `completed`, the reply is rejected. The `ConsultationMessage` model's boot callbacks prevent updates and deletes on existing messages regardless of review status. Managers score reviews (0-100) and add notes via the `updateQualityReview` endpoint; the review must transition through `in_review` before reaching `completed`.

---

## 12. Master Data Duplicate Detection Matching Rules

**Question:** The prompt specifies duplicate detection "by normalized name + date-of-birth or employee ID" but does not define the normalization algorithm, the matching threshold for name similarity, or how to handle encrypted DOB values during comparison.

**My Understanding:** Name normalization should at minimum lowercase and collapse whitespace. Since DOB is encrypted at rest, direct database-level comparison is not possible without decryption. Employee ID exact matching is more reliable and should carry higher confidence. The system should flag potential duplicates for human review rather than auto-merging.

**Solution:** The `DuplicateDetectionService` in `backend/app/Services/DuplicateDetectionService.php` normalizes names by lowercasing and collapsing whitespace (matching the `normalized_name` column stored on the model). Personnel duplicates are detected using three methods: (1) normalized name + matching decrypted DOB at confidence 0.95, (2) normalized name only with different or missing DOB at confidence 0.70, and (3) employee ID exact match at confidence 0.99. Organization duplicates use normalized name matching at confidence 0.85. DOB values are encrypted at rest; the detection service decrypts them in-memory during the detection run to enable comparison without compromising storage-level encryption. All detected pairs are stored in `duplicate_candidates` with a confidence score and require human review — no automatic merging occurs.

---

## 13. No-Show Policy and Slot Capacity Impact

**Question:** The prompt states "no-show after 10 minutes past start marks the slot consumed" but does not clarify whether the consumed slot's capacity should be restored if the no-show is later disputed or reversed.

**My Understanding:** A no-show should permanently consume the slot to prevent gaming the system. The slot was held for the applicant, and the advisor's time was allocated regardless of attendance. If a legitimate dispute arises, an administrator should manually create a new appointment rather than reversing the no-show.

**Solution:** The `AppointmentService.markNoShow()` method in `backend/app/Services/AppointmentService.php` transitions the appointment to `no_show` state and does not restore the slot's `available_qty`. The `no_show_marked_at` timestamp is recorded. The state transition is logged in `appointment_state_history` with the staff actor. There is no reverse-no-show API endpoint; corrections require booking a new appointment.

---

## 14. Reschedule and Cancel Time Windows for Staff vs. Applicants

**Question:** The prompt defines "reschedule allowed up to 24 hours before start" and "cancel allowed up to 12 hours before start" but does not specify whether these limits apply equally to staff and applicants, or whether staff/managers should have override capabilities.

**My Understanding:** The time windows are consumer-facing policy rules designed to protect the institution's scheduling. Staff and managers need the flexibility to make exceptions — for example, rescheduling on behalf of an applicant who calls with an emergency within the 24-hour window.

**Solution:** The `AppointmentService` in `backend/app/Services/AppointmentService.php` enforces the 24-hour reschedule and 12-hour cancel windows only for users with the `appointments.book` permission (applicants). Users with `appointments.manage` (advisors) or `appointments.override_policy` (managers) bypass these time checks. When a staff member overrides the policy, the `override_reason` field on the appointment record captures the justification, and the action is logged in the operation log and appointment state history.

---

## 15. Data Quality Timeliness Window Definition

**Question:** The prompt requires data quality metrics including "timeliness" but does not define what time window qualifies a record as "timely." Different entity types may have different expectations for update frequency.

**My Understanding:** A single configurable threshold is appropriate as a starting point, with the expectation that it can be tuned per entity type in the future. 90 days is a reasonable default for master data records — if an organization, personnel, or position record has not been reviewed in 90 days, it may be stale.

**Solution:** The `DataQualityService` in `backend/app/Services/DataQualityService.php` computes timeliness as the ratio of records updated within the last 90 days to total active records. This 90-day window is applied uniformly across organizations and personnel. The metric is computed nightly by the `ComputeDataQuality` command and stored in `data_quality_metrics` for trend reporting. The threshold is not yet externalized to configuration but could be made configurable per entity type in a future iteration.

---

## 16. Merge Workflow Execution and Lineage Preservation

**Question:** The prompt requires merge workflows for duplicates with "steward approval" but does not describe what happens to the source records after a merge. Should they be physically deleted, logically deleted, or retained with a pointer to the target?

**My Understanding:** Physical deletion would break referential integrity and audit trails. Logical deletion alone would leave ambiguity about why the record was deactivated. The safest approach is to retain source records with a clear pointer to the merge target, preserving full lineage.

**Solution:** The `MergeService.executeMerge()` method in `backend/app/Services/MergeService.php` sets source records to `retired` status with `merged_into_id` pointing to the target entity. Source rows are never physically deleted. The associated `duplicate_candidates` records are updated to `merged` status. The merge request itself transitions to `executed` with full audit logging. This preserves complete lineage — any historical reference to a retired record can be traced forward to the surviving entity through `merged_into_id`.

---

## 17. Sensitive Field Masking Granularity by Role

**Question:** The prompt states "sensitive fields are masked in the UI based on role" but does not specify which roles see which level of masking, or whether partial unmasking (e.g., showing last 4 digits of a government ID) is expected.

**My Understanding:** Most users should see fully masked values. Only users with an explicit sensitive-data permission should see unmasked values. Partial masking (showing trailing digits) provides enough context for identification without full exposure.

**Solution:** The `MaskingService` in `backend/app/Services/MaskingService.php` applies field-specific masking rules defined in `backend/config/security.php`: `date_of_birth` is masked as `**/**/****`, `government_id` as `***-**-{last4}`, and `institutional_id` as `****{last4}`. The `HasMaskedFields` trait applied to models checks whether the requesting user holds the `attachments.view_sensitive` permission — only users with this permission (currently only the `admin` role) see unmasked values. CSV exports via the `ReportingController` apply identical masking rules based on the exporting user's permissions.

---

## 18. Real-Time Status Updates Without WebSockets

**Question:** The prompt requires that ticket status changes are shown "in real time" but the system must operate fully on-premises without internet. WebSocket infrastructure adds deployment complexity. How should near-real-time updates be delivered?

**My Understanding:** Periodic polling is the pragmatic choice for an on-premises system where deployment simplicity is a priority. A short polling interval (10 seconds) provides a user experience that feels close to real-time without the infrastructure overhead of WebSockets or Server-Sent Events.

**Solution:** The frontend implements polling via `setInterval` in the `Dashboard.vue` and `TicketDetail.vue` components, calling the `/api/dashboard/poll` and `/api/tickets/{id}/poll` endpoints respectively at a 10-second default interval. The poll endpoints return lightweight payloads (message counts, status, last update timestamps) rather than full ticket data, minimizing bandwidth. The API response `meta.poll_after_ms` field allows the server to dynamically adjust the polling interval. The Axios interceptor adds a `X-Correlation-ID` header to each poll request for traceability.

---

## 19. Organization Code Format Enforcement and Validation

**Question:** The prompt gives `ORG-000123` as an example of a coding rule but does not specify whether this format should be strictly enforced, auto-generated, or user-provided. Should other entity types follow similar patterns?

**My Understanding:** The code should be user-provided but strictly validated to maintain data consistency. The format should be enforced at both the application and database levels. Other entity types (positions, course categories) should follow their own coding patterns but with less rigid formatting since no specific format is given in the prompt.

**Solution:** The `OrganizationController.store()` method validates the `code` field with a regex pattern enforcing the `ORG-XXXXXX` format (prefix `ORG-` followed by exactly 6 digits). The `organizations` table has a unique constraint on the `code` column. Positions and course categories also have unique `code` columns but without a prescribed prefix format. The `DataDictionary` model supports defining `validation_rule_ref` entries that can reference coding patterns, enabling configurable validation rules per entity type.

---

## 20. Multi-Role User Permission Resolution

**Question:** The prompt defines five distinct roles but does not clarify whether a single user can hold multiple roles simultaneously, or how conflicting permissions should be resolved if they can.

**My Understanding:** In an educational institution, a single person may wear multiple hats — for example, an advisor who is also a data steward. The system should support multiple concurrent role assignments, with permissions unioned across all active roles to provide the broadest access the user is entitled to.

**Solution:** The `user_role_scopes` table supports multiple records per user, each with its own `role`, `department_scope`, and `content_permissions`. The `User` model's permission-checking methods in `backend/app/Models/User.php` union permissions across all active role-scope records. The frontend `useAuthStore` in `frontend/src/store/auth.js` computes the aggregate permission set from all roles. The unique constraint is on `[user_id, role, department_scope]`, allowing the same role to be scoped to different departments.
