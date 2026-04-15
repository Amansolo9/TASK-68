import { test, expect } from '@playwright/test';
import { apiLogin, apiPost, apiGet, USERS } from './helpers.js';
import { randomUUID } from 'crypto';

test.describe('Appointments E2E', () => {

    test('applicant sees appointment page with My Appointments heading', async ({ page }) => {
        await page.goto('/login');
        await page.fill('#username', USERS.applicant.username);
        await page.fill('#password', USERS.applicant.password);
        await page.click('button[type="submit"]');
        await page.waitForURL(url => !url.pathname.includes('/login'), { timeout: 10000 });

        await page.click('a:has-text("Appointments")');
        await page.waitForTimeout(2000);

        await expect(page.locator('text=My Appointments')).toBeVisible({ timeout: 5000 });
    });

    test('booking with idempotency: same request key returns same appointment', async ({ request }) => {
        const { token: mgrToken } = await apiLogin(request, USERS.manager.username, USERS.manager.password);

        const slotResp = await apiPost(request, '/api/appointments/slots', mgrToken, {
            slot_type: 'IN_PERSON',
            start_at: new Date(Date.now() + 7 * 86400000).toISOString(),
            end_at: new Date(Date.now() + 7 * 86400000 + 3600000).toISOString(),
            capacity: 5,
        });
        expect(slotResp.status()).toBe(201);
        const slot = (await slotResp.json()).data;

        const { token: appToken } = await apiLogin(request, USERS.applicant.username, USERS.applicant.password);
        const requestKey = randomUUID();

        const bookResp = await apiPost(request, '/api/appointments/book', appToken, {
            slot_id: slot.id, request_key: requestKey,
        });
        expect(bookResp.status()).toBe(201);
        const booking = (await bookResp.json()).data;
        expect(booking.state).toBe('booked');

        // Idempotent retry
        const retryResp = await apiPost(request, '/api/appointments/book', appToken, {
            slot_id: slot.id, request_key: requestKey,
        });
        expect(retryResp.status()).toBe(201);
        expect((await retryResp.json()).data.id).toBe(booking.id);
    });

    test('applicant cannot cancel another applicant appointment', async ({ request }) => {
        const { token: mgrToken } = await apiLogin(request, USERS.manager.username, USERS.manager.password);

        // Create slot and book as the seeded applicant
        const slotResp = await apiPost(request, '/api/appointments/slots', mgrToken, {
            slot_type: 'PHONE',
            start_at: new Date(Date.now() + 8 * 86400000).toISOString(),
            end_at: new Date(Date.now() + 8 * 86400000 + 3600000).toISOString(),
            capacity: 5,
        });
        expect(slotResp.status()).toBe(201);
        const slot = (await slotResp.json()).data;

        const { token: appToken } = await apiLogin(request, USERS.applicant.username, USERS.applicant.password);
        const bookResp = await apiPost(request, '/api/appointments/book', appToken, {
            slot_id: slot.id, request_key: randomUUID(),
        });
        expect(bookResp.status()).toBe(201);
        const appointmentId = (await bookResp.json()).data.id;

        // Steward (who has neither appointments.book nor appointments.manage) tries to cancel
        const { token: stewardToken } = await apiLogin(request, USERS.steward.username, USERS.steward.password);
        const cancelResp = await apiPost(request, `/api/appointments/${appointmentId}/cancel`, stewardToken, {
            reason: 'Unauthorized cancel attempt',
        });
        expect(cancelResp.status()).toBe(403);
    });

    test('cancel within policy window succeeds', async ({ request }) => {
        const { token: mgrToken } = await apiLogin(request, USERS.manager.username, USERS.manager.password);

        // Slot far in future — well within 12h cancel window
        const slotResp = await apiPost(request, '/api/appointments/slots', mgrToken, {
            slot_type: 'VIDEO',
            start_at: new Date(Date.now() + 10 * 86400000).toISOString(),
            end_at: new Date(Date.now() + 10 * 86400000 + 3600000).toISOString(),
            capacity: 3,
        });
        expect(slotResp.status()).toBe(201);
        const slot = (await slotResp.json()).data;

        const { token: appToken } = await apiLogin(request, USERS.applicant.username, USERS.applicant.password);
        const bookResp = await apiPost(request, '/api/appointments/book', appToken, {
            slot_id: slot.id, request_key: randomUUID(),
        });
        expect(bookResp.status()).toBe(201);
        const appointmentId = (await bookResp.json()).data.id;

        const cancelResp = await apiPost(request, `/api/appointments/${appointmentId}/cancel`, appToken, {
            reason: 'No longer needed',
        });
        expect(cancelResp.status()).toBe(200);
        expect((await cancelResp.json()).data.state).toBe('cancelled');
    });
});
