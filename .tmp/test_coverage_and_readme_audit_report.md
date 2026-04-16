# Test Coverage Audit

## Scope and Method
- Static inspection only (no execution).
- Audited files: `repo/backend/routes/api.php`, `repo/backend/tests/**/*`, `repo/frontend/tests/**/*`, `repo/frontend/package.json`, `repo/frontend/vite.config.js`, `repo/README.md`, `repo/run_tests.sh`.

## Project Type Detection
- README explicitly declares: `Project type: Fullstack` (`repo/README.md:3`).
- Final type: **fullstack**.

## Backend Endpoint Inventory
Source: `repo/backend/routes/api.php`.

Total endpoints: **103**

## API Test Mapping Table
Legend: `true no-mock HTTP` = request sent via Laravel HTTP test layer and no backend mock/stub detected.

| Endpoint | Covered | Test type | Test files | Evidence |
|---|---|---|---|---|
| POST /api/auth/login | yes | true no-mock HTTP | AuthenticationTest.php | `test_login_with_valid_credentials` |
| POST /api/auth/captcha | yes | true no-mock HTTP | CaptchaTest.php | `test_captcha_generation_returns_challenge` |
| GET /api/auth/captcha/{key} | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_auth_captcha_image_endpoint` |
| POST /api/auth/logout | yes | true no-mock HTTP | AuthenticationTest.php | `test_logout_invalidates_session` |
| GET /api/auth/session | yes | true no-mock HTTP | AuthenticationTest.php | `test_authenticated_session_endpoint` |
| POST /api/auth/refresh | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_auth_refresh_returns_new_token` |
| POST /api/mfa/setup | yes | true no-mock HTTP | MfaTest.php | `test_mfa_setup_returns_otpauth_uri` |
| POST /api/mfa/verify | yes | true no-mock HTTP | MfaTest.php | `test_mfa_verify_with_invalid_code_fails` |
| POST /api/mfa/verify-login | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_mfa_verify_login_without_session_fails` |
| POST /api/mfa/recovery/use | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_mfa_recovery_use_with_invalid_code_fails` |
| GET /api/dashboard | yes | true no-mock HTTP | AuditFixesTest.php | `test_protected_route_blocked_before_mfa` |
| GET /api/dashboard/poll | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_dashboard_poll_returns_timestamp` |
| GET /api/published-plans | yes | true no-mock HTTP | PublishedPlanBrowsingTest.php | `test_applicant_can_retrieve_published_plans` |
| GET /api/published-plans/{admissionsPlan} | yes | true no-mock HTTP | PublishedPlanBrowsingTest.php | `test_applicant_can_retrieve_published_plan_detail` |
| GET /api/lookup/dictionaries/{type} | yes | true no-mock HTTP | MasterDataTest.php | `test_dictionary_lookup_returns_active_only` |
| GET /api/organizations | yes | true no-mock HTTP | RbacAuthorizationTest.php | `test_all_authenticated_users_can_read_organizations` |
| GET /api/organizations/{organization} | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_read_organization_detail` |
| GET /api/personnel | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_read_personnel_list` |
| GET /api/personnel/{personnel} | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_read_personnel_detail` |
| GET /api/positions | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_read_positions_list` |
| GET /api/positions/{position} | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_read_position_detail` |
| GET /api/course-categories | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_read_course_categories_list` |
| GET /api/course-categories/{courseCategory} | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_read_course_category_detail` |
| POST /api/mfa/disable | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_mfa_disable_requires_admin` |
| POST /api/mfa/recovery/generate | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_mfa_generate_recovery_codes` |
| GET /api/users | yes | true no-mock HTTP | RbacAuthorizationTest.php | `test_admin_can_access_user_management` |
| POST /api/users | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_admin_can_create_user` |
| GET /api/users/{user} | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_admin_can_view_user_detail` |
| PUT /api/users/{user} | yes | true no-mock HTTP | AcceptanceFixesTest.php | `test_nested_payload_redaction` |
| POST /api/users/{user}/deactivate | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_admin_can_deactivate_user` |
| POST /api/users/{user}/activate | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_admin_can_activate_user` |
| POST /api/users/{user}/unlock | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_admin_can_unlock_user` |
| POST /api/users/{user}/reset-password | yes | true no-mock HTTP | AcceptanceFixesTest.php | `test_password_reset_does_not_persist_sensitive_fields` |
| PUT /api/users/{user}/roles | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_admin_can_update_user_roles` |
| GET /api/audit-logs | yes | true no-mock HTTP | RbacAuthorizationTest.php | `test_admin_can_access_audit_logs` |
| GET /api/audit-logs/verify/chain | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_admin_can_verify_audit_chain` |
| GET /api/audit-logs/{auditLog} | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_admin_can_view_audit_log_detail` |
| GET /api/dictionaries | yes | true no-mock HTTP | RbacAuthorizationTest.php | `test_steward_can_access_dictionaries` |
| POST /api/dictionaries | yes | true no-mock HTTP | MasterDataTest.php | `test_dictionary_crud_operations` |
| GET /api/dictionaries/{dictionary} | yes | true no-mock HTTP | MasterDataTest.php | `test_dictionary_crud_operations` |
| PUT /api/dictionaries/{dictionary} | yes | true no-mock HTTP | MasterDataTest.php | `test_dictionary_crud_operations` |
| DELETE /api/dictionaries/{dictionary} | yes | true no-mock HTTP | MasterDataTest.php | `test_dictionary_crud_operations` |
| POST /api/organizations | yes | true no-mock HTTP | MasterDataTest.php | `test_create_organization_with_valid_code` |
| PUT /api/organizations/{organization} | yes | true no-mock HTTP | MasterDataTest.php | `test_organization_update_creates_version_history` |
| DELETE /api/organizations/{organization} | yes | true no-mock HTTP | MasterDataTest.php | `test_organization_soft_delete_preserves_record` |
| POST /api/personnel | yes | true no-mock HTTP | MasterDataTest.php | `test_create_personnel_with_encrypted_fields` |
| PUT /api/personnel/{personnel} | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_steward_can_update_personnel` |
| DELETE /api/personnel/{personnel} | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_steward_can_delete_personnel` |
| POST /api/positions | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_steward_can_create_position` |
| PUT /api/positions/{position} | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_steward_can_update_position` |
| DELETE /api/positions/{position} | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_steward_can_delete_position` |
| POST /api/course-categories | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_steward_can_create_course_category` |
| PUT /api/course-categories/{courseCategory} | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_steward_can_update_course_category` |
| DELETE /api/course-categories/{courseCategory} | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_steward_can_delete_course_category` |
| GET /api/admissions-plans | yes | true no-mock HTTP | PublishedPlanBrowsingTest.php | `test_applicant_cannot_access_internal_plan_endpoints` |
| POST /api/admissions-plans | yes | true no-mock HTTP | AdmissionsPlanTest.php | `test_manager_can_create_plan` |
| GET /api/admissions-plans/{admissionsPlan} | yes | true no-mock HTTP | PublishedPlanBrowsingTest.php | `test_applicant_cannot_access_internal_plan_endpoints` |
| POST /api/admissions-plans/{admissionsPlan}/versions | yes | true no-mock HTTP | AdmissionsPlanTest.php | `test_compare_two_versions_shows_differences` |
| POST /api/admissions-plans/{admissionsPlan}/derive-from-published | yes | true no-mock HTTP | AdmissionsPlanTest.php | `test_derive_new_version_from_published` |
| GET /api/admissions-plans/{admissionsPlan}/versions/{version} | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_manager_can_view_version_detail` |
| PUT /api/admissions-plans/{admissionsPlan}/versions/{version} | yes | true no-mock HTTP | AdmissionsPlanTest.php | `test_full_lifecycle_draft_to_published` |
| POST /api/admissions-plans/{admissionsPlan}/versions/{version}/programs | yes | true no-mock HTTP | AdmissionsPlanTest.php | `test_add_program_and_track_to_draft` |
| PUT /api/admissions-plans/{admissionsPlan}/versions/{version}/programs/{program} | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_manager_can_update_program` |
| DELETE /api/admissions-plans/{admissionsPlan}/versions/{version}/programs/{program} | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_manager_can_delete_program` |
| POST /api/admissions-plans/{admissionsPlan}/versions/{version}/programs/{program}/tracks | yes | true no-mock HTTP | AdmissionsPlanTest.php | `test_add_program_and_track_to_draft` |
| PUT /api/admissions-plans/{admissionsPlan}/versions/{version}/programs/{program}/tracks/{track} | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_manager_can_update_track` |
| DELETE /api/admissions-plans/{admissionsPlan}/versions/{version}/programs/{program}/tracks/{track} | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_manager_can_delete_track` |
| POST /api/admissions-plans/{admissionsPlan}/versions/{version}/transition | yes | true no-mock HTTP | AdmissionsPlanTest.php | `test_full_lifecycle_draft_to_published` |
| POST /api/admissions-plans/{admissionsPlan}/compare | yes | true no-mock HTTP | AdmissionsPlanTest.php | `test_compare_two_versions_shows_differences` |
| GET /api/admissions-plans/{admissionsPlan}/versions/{version}/integrity | yes | true no-mock HTTP | AdmissionsPlanTest.php | `test_integrity_verification_passes_for_valid_published_version` |
| POST /api/tickets | yes | true no-mock HTTP | ConsultationTicketTest.php | `test_applicant_can_create_ticket` |
| GET /api/tickets | yes | true no-mock HTTP | AcceptanceFixesTest.php | `test_content_permission_grants_access` |
| GET /api/tickets/{ticket} | yes | true no-mock HTTP | AuditFixesTest.php | `test_non_assigned_out_of_dept_advisor_gets_403` |
| GET /api/tickets/{ticket}/poll | yes | true no-mock HTTP | ConsultationTicketTest.php | `test_ticket_poll_endpoint_returns_status` |
| POST /api/tickets/{ticket}/reply | yes | true no-mock HTTP | ConsultationTicketTest.php | `test_advisor_can_reply_to_assigned_ticket` |
| POST /api/tickets/{ticket}/transition | yes | true no-mock HTTP | ConsultationTicketTest.php | `test_ticket_lifecycle_transitions` |
| POST /api/tickets/{ticket}/reassign | yes | true no-mock HTTP | ConsultationTicketTest.php | `test_manager_can_reassign_with_reason` |
| GET /api/quality-reviews | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_manager_can_list_quality_reviews` |
| PUT /api/quality-reviews/{review} | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_manager_can_update_quality_review` |
| GET /api/appointments/slots | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_staff_can_list_slots` |
| POST /api/appointments/slots | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_staff_can_create_slot` |
| GET /api/appointments/my | yes | true no-mock HTTP | AppointmentRoutingTest.php | `test_applicant_can_access_my_appointments` |
| POST /api/appointments/book | yes | true no-mock HTTP | AppointmentTest.php | `test_applicant_can_book_appointment` |
| GET /api/appointments | yes | true no-mock HTTP | AppointmentRoutingTest.php | `test_advisor_can_access_staff_appointment_listing` |
| POST /api/appointments/{appointment}/reschedule | yes | true no-mock HTTP | AppointmentTest.php | `test_reschedule_within_24h_window` |
| POST /api/appointments/{appointment}/cancel | yes | true no-mock HTTP | AppointmentTest.php | `test_cancel_within_12h_window` |
| GET /api/appointments/{appointment} | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_staff_can_view_appointment_detail` |
| POST /api/appointments/{appointment}/no-show | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_staff_can_mark_no_show_via_api` |
| POST /api/appointments/{appointment}/complete | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_staff_can_mark_complete_via_api` |
| GET /api/duplicates | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_steward_can_list_duplicates` |
| POST /api/duplicates/detect | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_steward_can_run_detection` |
| PUT /api/duplicates/{duplicate} | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_steward_can_update_duplicate` |
| GET /api/merge-requests | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_steward_can_list_merge_requests` |
| POST /api/merge-requests | yes | true no-mock HTTP | Phase5Test.php | `test_merge_request_lifecycle` |
| POST /api/merge-requests/{mergeRequest}/transition | yes | true no-mock HTTP | Phase5Test.php | `test_merge_request_lifecycle` |
| POST /api/merge-requests/{mergeRequest}/execute | yes | true no-mock HTTP | Phase5Test.php | `test_merge_request_lifecycle` |
| GET /api/reports/tickets | yes | true no-mock HTTP | Phase5Test.php | `test_reporting_endpoints_accessible_by_manager` |
| GET /api/reports/appointments | yes | true no-mock HTTP | Phase5Test.php | `test_reporting_endpoints_accessible_by_manager` |
| GET /api/reports/plans | yes | true no-mock HTTP | Phase5Test.php | `test_reporting_endpoints_accessible_by_manager` |
| GET /api/reports/data-quality | yes | true no-mock HTTP | Phase5Test.php | `test_reporting_endpoints_accessible_by_manager` |
| GET /api/reports/data-quality/trend | yes | true no-mock HTTP | EndpointCoverageTest.php | `test_data_quality_trend_endpoint` |
| GET /api/reports/merges | yes | true no-mock HTTP | Phase5Test.php | `test_reporting_endpoints_accessible_by_manager` |
| GET /api/reports/export | yes | true no-mock HTTP | Phase5Test.php | `test_csv_export` |

## API Test Classification
1. True No-Mock HTTP
- Present across API suite, including new `repo/backend/tests/api_tests/EndpointCoverageTest.php`.
- No backend route/controller/service mocks detected.

2. HTTP with Mocking
- **None detected** (backend API tests).

3. Non-HTTP (unit/integration without HTTP)
- Still present in API test namespace, e.g. service-level assertions in `CaptchaTest.php`, `AuditFixesTest.php`, `Phase5Test.php`.

## Mock Detection Rules
### Backend
- No `jest.mock`, `vi.mock`, `sinon.stub`, `Mockery`, `$this->mock`, `$this->partialMock` in backend tests.

### Frontend
- Mocking present in unit tests, including new file:
  - `repo/frontend/tests/unit_tests/PagesCoverage.spec.js` (`vi.mock('../../src/utils/api')`, `vi.mock('vue-router')`, `vi.mock('../../src/store/auth')`)
  - Existing files: `TicketDetail.spec.js`, `Dashboard.spec.js`, `LoginPage.spec.js`, `MfaVerify.spec.js`, etc.

## Coverage Summary
- Total endpoints: **103**
- Endpoints with HTTP tests: **103**
- Endpoints with TRUE no-mock HTTP tests: **103**
- HTTP coverage: **100%**
- True API coverage: **100%**

## Unit Test Analysis
### Backend Unit Tests
Files:
- `AppointmentServiceTest.php`, `AuditServiceTest.php`, `DuplicateDetectionTest.php`, `EncryptionServiceTest.php`, `MaskingServiceTest.php`, `SessionTokenServiceTest.php`, `TicketServiceTest.php`

Covered modules:
- Services: appointments, audit, duplicate detection, encryption, masking, session token, ticket.

Important backend modules not directly unit-tested:
- Services: `AuthService`, `CaptchaService`, `DataQualityService`, `LockService`, `MasterDataVersionService`, `MergeService`, `MfaService`, `PlanVersionService`, `SignedSessionGuard`.
- Middleware/guards mainly covered indirectly via API tests.

### Frontend Unit Tests (STRICT)
Frontend test files:
- Existing 9 files under `repo/frontend/tests/unit_tests/*`
- New: `repo/frontend/tests/unit_tests/PagesCoverage.spec.js`

Framework/tools detected:
- Vitest + Vue Test Utils + jsdom (`repo/frontend/package.json`, `repo/frontend/vite.config.js`).

Components/modules covered:
- Direct import/render coverage: `Login.vue`, `Dashboard.vue`, `MfaVerify.vue`, `TicketDetail.vue`, `auth store`.
- New file adds mounted behavioral tests across additional pages/components including `AuditLogs.vue`, `Organizations.vue`, `Users.vue`, `Positions.vue`, `CourseCategories.vue`, `Dictionaries.vue`, `PersonnelList.vue`, `MfaSetup.vue`, `AdmissionsPlanDetail.vue`, `PublishedPlanDetail.vue`, `UserDetail.vue`, and `AppLayout.vue`.

Important frontend modules not strongly unit-tested (behavioral):
- `src/App.vue`, `src/app.js`.
- Router guard behavior still partly synthetic in `RouterGuard.spec.js`.

**Frontend unit tests: PRESENT**

**CRITICAL GAP**
- Not triggered. Frontend unit tests are present with direct component mounting evidence and framework/tooling evidence.

### Cross-Layer Observation
- Balance improved significantly in backend API endpoint coverage (now complete).
- Frontend coverage depth improved materially, though some app-shell/bootstrap areas remain lightly tested.

## API Observability Check
- Strong: endpoint + inputs + response/body assertions are now widely present in `EndpointCoverageTest.php` (schema + state checks).
- Weak pockets: some authorization paths in older suites remain status-centric.
- Verdict: **strong overall, with limited weak pockets**.

## Test Quality and Sufficiency
- Success/failure/auth/permission paths: broad and strong.
- Edge cases: good in existing suites.
- Depth: substantially improved; new coverage tests include response schema and persisted-state assertions.
- `run_tests.sh`: Docker-only execution flow, but still performs runtime dependency installs inside containers.

## End-to-End Expectations
- Fullstack E2E files exist (`repo/frontend/tests/e2e/*.js`) and cover FE↔BE flows by script design.
- Static-only audit cannot validate runtime pass/fail.

## Tests Check
- Endpoint breadth: complete.
- No-mock backend HTTP style: strong.
- Assertion depth consistency: good.

## Test Coverage Score (0-100)
**91/100**

## Score Rationale
- + 100% endpoint HTTP coverage with direct route-hitting tests.
- + No backend mocking patterns detected in API tests.
- + Frontend unit suite now includes significantly more mounted behavioral tests across previously untested pages.
- - Router guard and app bootstrap (`App.vue`/`app.js`) still have limited direct behavioral coverage.
- - Runtime dependency installation still occurs (inside Docker containers).

## Key Gaps
- Add direct behavioral tests for `App.vue` and bootstrap flow in `src/app.js`.
- Replace synthetic guard simulation in `RouterGuard.spec.js` with direct test of real router guard behavior.
- Reduce reliance on runtime dependency installation by using prebuilt/pinned test images or cached volumes.

## Confidence and Assumptions
- Confidence: high for static route/test mapping and README gate checks.
- Assumption: endpoint inventory based on `repo/backend/routes/api.php` only.

---

# README Audit

## README Location
- Present at required path: `repo/README.md`.

## Hard Gates
### Formatting
- PASS: well-structured markdown.

### Startup Instructions (Backend/Fullstack)
- PASS: includes exact `docker-compose up --build -d` (`repo/README.md:14`).

### Access Method
- PASS: URL and port documented (`http://localhost:8000`).

### Verification Method
- PASS: explicit curl/UI verification steps in `Verify the system is running` section.

### Environment Rules (STRICT)
- PARTIAL FAIL risk: README now states fully Docker-contained workflow, and local install pathways were removed.
- Runtime installs still occur inside containers (`npm install`, `playwright install`, composer dependency resolution) in `repo/run_tests.sh`.
- README itself does not instruct manual `npm install`/`pip install`/`apt-get`.

### Demo Credentials (Conditional auth)
- PASS: username/password + roles listed.

## Engineering Quality
- Tech stack clarity: strong.
- Architecture/security/workflow explanation: strong.
- Testing guidance: clear and improved; remaining strict concern is runtime installs (containerized but still install-at-run).

## High Priority Issues
- Strict environment policy gap: runtime dependency installation still happens during test execution (inside Docker).

## Medium Priority Issues
- Manual test command section still emphasizes local toolchain commands (`vendor/bin/phpunit`, `npx vitest`) alongside Docker-centric guidance.

## Low Priority Issues
- None significant beyond consistency/documentation alignment.

## Hard Gate Failures
- No direct README hard-gate literal failure remains.
- Strict environment-rule intent is **partially violated** by install-at-runtime behavior in test workflow.

## README Verdict
**PARTIAL PASS**

## Recheck Delta
- `README.md` remains compliant on project type declaration, startup command (`docker-compose up`), access method, verification steps, and credentials.
- `run_tests.sh` now enforces Docker execution paths for backend/frontend/e2e (local install paths removed).
- Backend and frontend sufficiency improved: new endpoint and page tests now include more behavioral and state assertions.
- Strict environment-rule concern remains due runtime installs inside containers.

---

## Final Verdicts
- Test Coverage Audit: **PASS (strong coverage breadth and improved depth; minor residual quality gaps).**
- README Audit: **PARTIAL PASS (hard gates mostly met; environment-rule consistency gap remains).**
