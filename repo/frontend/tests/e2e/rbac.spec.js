import { test, expect } from '@playwright/test';
import { apiLogin, apiGet, apiPost, USERS } from './helpers.js';

test.describe('RBAC & Security E2E', () => {

    test('applicant cannot access admin user management', async ({ request }) => {
        const { token } = await apiLogin(request, USERS.applicant.username, USERS.applicant.password);
        const resp = await apiGet(request, '/api/users', token);
        expect(resp.status()).toBe(403);
    });

    test('applicant cannot access audit logs', async ({ request }) => {
        const { token } = await apiLogin(request, USERS.applicant.username, USERS.applicant.password);
        const resp = await apiGet(request, '/api/audit-logs', token);
        expect(resp.status()).toBe(403);
    });

    test('applicant cannot access internal plan management', async ({ request }) => {
        const { token } = await apiLogin(request, USERS.applicant.username, USERS.applicant.password);
        const resp = await apiGet(request, '/api/admissions-plans', token);
        expect(resp.status()).toBe(403);
    });

    test('applicant CAN access published plans', async ({ request }) => {
        const { token } = await apiLogin(request, USERS.applicant.username, USERS.applicant.password);
        const resp = await apiGet(request, '/api/published-plans', token);
        expect(resp.status()).toBe(200);
    });

    test('applicant cannot access reports', async ({ request }) => {
        const { token } = await apiLogin(request, USERS.applicant.username, USERS.applicant.password);
        const resp = await apiGet(request, '/api/reports/tickets', token);
        expect(resp.status()).toBe(403);
    });

    test('applicant cannot access merge workflows', async ({ request }) => {
        const { token } = await apiLogin(request, USERS.applicant.username, USERS.applicant.password);
        const resp = await apiGet(request, '/api/duplicates', token);
        expect(resp.status()).toBe(403);
    });

    test('unauthenticated API returns 401', async ({ request }) => {
        const resp = await request.get('http://localhost:8000/api/dashboard');
        expect(resp.status()).toBe(401);
    });

    test('invalid token returns 401', async ({ request }) => {
        const resp = await request.get('http://localhost:8000/api/dashboard', {
            headers: { Authorization: 'Bearer invalid-garbage-token' },
        });
        expect(resp.status()).toBe(401);
    });

    test('CAPTCHA endpoint works offline', async ({ request }) => {
        const resp = await request.post('http://localhost:8000/api/auth/captcha');
        expect(resp.status()).toBe(200);
        const body = await resp.json();
        expect(body.data.challenge_key).toBeTruthy();
        expect(body.data.question).toMatch(/\+/);
    });

    test('admin MFA required for protected routes', async ({ request }) => {
        const { token, mfaRequired } = await apiLogin(request, USERS.admin.username, USERS.admin.password);
        expect(mfaRequired).toBe(true);

        // Try accessing a protected route without MFA verification
        const resp = await apiGet(request, '/api/dashboard', token);
        expect(resp.status()).toBe(403);
        const body = await resp.json();
        expect(body.error.code).toBe('MFA_REQUIRED');
    });

    test('masking: sensitive fields not exposed in API', async ({ request }) => {
        const { token } = await apiLogin(request, USERS.applicant.username, USERS.applicant.password);

        // Session endpoint should not expose password_hash or encrypted fields
        const resp = await request.get('http://localhost:8000/api/auth/session', {
            headers: { Authorization: `Bearer ${token}` },
        });
        const body = await resp.json();
        const userData = body.data.user;
        expect(userData.password_hash).toBeUndefined();
        expect(userData.encrypted_date_of_birth).toBeUndefined();
        expect(userData.encrypted_government_id).toBeUndefined();
    });
});
