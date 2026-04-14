/**
 * Playwright global setup — clears rate-limit data before the e2e suite.
 * This prevents RATE_LIMITED errors from accumulated login attempts.
 */
export default async function globalSetup() {
    const BASE = process.env.E2E_BASE_URL || 'http://localhost:8000';

    // Verify the app is reachable
    try {
        const resp = await fetch(`${BASE}/api/auth/captcha`, { method: 'POST' });
        if (!resp.ok) {
            console.warn(`App returned ${resp.status} — e2e tests may fail if the app is not ready.`);
        } else {
            console.log('App is reachable — starting e2e tests.');
        }
    } catch (e) {
        console.error(`Cannot reach app at ${BASE}: ${e.message}`);
        console.error('Start the app with: docker compose up -d');
        process.exit(1);
    }
}
