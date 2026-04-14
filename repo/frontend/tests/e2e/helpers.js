/**
 * Shared helpers for e2e tests.
 * All tests hit the running Docker stack at http://localhost:8000.
 */

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8000';

/**
 * Login via API and return { token, user }.
 */
export async function apiLogin(request, username, password) {
    const resp = await request.post(`${BASE}/api/auth/login`, {
        data: { username, password },
    });
    const body = await resp.json();
    if (!body.data?.token) throw new Error(`Login failed for ${username}: ${JSON.stringify(body.error)}`);
    return { token: body.data.token, user: body.data.user, mfaRequired: body.data.mfa_required };
}

/**
 * Make an authenticated API request.
 */
export async function apiGet(request, path, token) {
    return request.get(`${BASE}${path}`, {
        headers: { Authorization: `Bearer ${token}` },
    });
}

export async function apiPost(request, path, token, data = {}) {
    return request.post(`${BASE}${path}`, {
        headers: { Authorization: `Bearer ${token}` },
        data,
    });
}

/** Default test credentials from the seeder */
export const USERS = {
    admin:     { username: 'admin',     password: 'AdminPassword123!' },
    manager:   { username: 'manager',   password: 'ManagerPassword123!' },
    advisor:   { username: 'advisor',   password: 'AdvisorPassword123!' },
    steward:   { username: 'steward',   password: 'StewardPassword123!' },
    applicant: { username: 'applicant', password: 'ApplicantPassword123!' },
};
