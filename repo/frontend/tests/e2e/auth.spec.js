import { test, expect } from '@playwright/test';
import { USERS } from './helpers.js';

test.describe('Authentication E2E', () => {

    test('login page loads and shows form', async ({ page }) => {
        await page.goto('/login');
        await expect(page.locator('h1')).toContainText('Admissions System');
        await expect(page.locator('input#username')).toBeVisible();
        await expect(page.locator('input#password')).toBeVisible();
        await expect(page.locator('button[type="submit"]')).toBeVisible();
    });

    test('successful login redirects to dashboard or MFA', async ({ page }) => {
        await page.goto('/login');
        await page.fill('#username', USERS.applicant.username);
        await page.fill('#password', USERS.applicant.password);
        await page.click('button[type="submit"]');

        // Should redirect away from login
        await page.waitForURL(url => !url.pathname.includes('/login'), { timeout: 10000 });

        // Applicant has no MFA, so should land on dashboard
        const url = page.url();
        expect(url.includes('/login')).toBe(false);
    });

    test('invalid credentials show error message', async ({ page }) => {
        await page.goto('/login');
        await page.fill('#username', 'nonexistent');
        await page.fill('#password', 'wrongpassword');
        await page.click('button[type="submit"]');

        // Wait for error to appear
        await expect(page.locator('.alert-error')).toBeVisible({ timeout: 5000 });
    });

    test('admin login triggers MFA challenge', async ({ page }) => {
        await page.goto('/login');
        await page.fill('#username', USERS.admin.username);
        await page.fill('#password', USERS.admin.password);
        await page.click('button[type="submit"]');

        // Admin requires MFA — should redirect to /mfa
        await page.waitForURL('**/mfa', { timeout: 10000 });
        await expect(page.locator('h1')).toContainText('Two-Factor Authentication');
    });

    test('unauthenticated access redirects to login', async ({ page }) => {
        await page.goto('/');
        await page.waitForURL('**/login', { timeout: 5000 });
    });

    test('logout returns to login page', async ({ page }) => {
        // Login first
        await page.goto('/login');
        await page.fill('#username', USERS.applicant.username);
        await page.fill('#password', USERS.applicant.password);
        await page.click('button[type="submit"]');
        await page.waitForURL(url => !url.pathname.includes('/login'), { timeout: 10000 });

        // Click logout
        await page.click('button:has-text("Logout")');
        await page.waitForURL('**/login', { timeout: 5000 });
    });
});
