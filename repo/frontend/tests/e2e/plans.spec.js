import { test, expect } from '@playwright/test';
import { apiLogin, apiPost, USERS } from './helpers.js';

test.describe('Admissions Plans E2E', () => {

    test('applicant can browse published plans', async ({ page, request }) => {
        // Login as applicant via UI
        await page.goto('/login');
        await page.fill('#username', USERS.applicant.username);
        await page.fill('#password', USERS.applicant.password);
        await page.click('button[type="submit"]');
        await page.waitForURL(url => !url.pathname.includes('/login'), { timeout: 10000 });

        // Navigate to admissions plans
        await page.click('a:has-text("Admissions Plans")');
        await page.waitForTimeout(1000);

        // Applicant should see published plans page (not internal management)
        const pageContent = await page.textContent('body');
        // Should NOT see "Create Plan" button (that's for managers)
        const createBtn = page.locator('button:has-text("Create Plan")');
        await expect(createBtn).toHaveCount(0);
    });

    test('applicant cannot access internal plan management API', async ({ request }) => {
        const { token } = await apiLogin(request, USERS.applicant.username, USERS.applicant.password);

        const resp = await request.get('http://localhost:8000/api/admissions-plans', {
            headers: { Authorization: `Bearer ${token}` },
        });

        // Should be 403 — applicants don't have plans.create_version
        expect(resp.status()).toBe(403);
    });

    test('applicant can access published plans API', async ({ request }) => {
        const { token } = await apiLogin(request, USERS.applicant.username, USERS.applicant.password);

        const resp = await request.get('http://localhost:8000/api/published-plans', {
            headers: { Authorization: `Bearer ${token}` },
        });

        expect(resp.status()).toBe(200);
        const body = await resp.json();
        expect(body.error).toBeNull();
    });
});
