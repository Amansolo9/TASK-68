import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './tests/e2e',
    timeout: 30000,
    retries: 0,
    workers: 1,  // Serialize to avoid rate-limit collisions from parallel logins
    use: {
        baseURL: process.env.E2E_BASE_URL || 'http://localhost:8000',
        headless: true,
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: { browserName: 'chromium' },
        },
    ],
    reporter: [['list']],
    globalSetup: './tests/e2e/global-setup.js',
});
