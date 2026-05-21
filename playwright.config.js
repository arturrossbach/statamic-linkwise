import { defineConfig } from '@playwright/test';
import os from 'os';

export default defineConfig({
    testDir: './tests/e2e',
    timeout: 60000,
    retries: 1,
    // Default = half of CPU cores. Override with PLAYWRIGHT_WORKERS env var
    // when you want to push harder (8-core M1 → 6 workers). Suite was running
    // ~30 min on workers:3 — bumping defaults gets that down to ~10-15 min.
    workers: parseInt(process.env.PLAYWRIGHT_WORKERS || Math.max(2, Math.floor(os.cpus().length / 2)), 10),

    use: {
        // Env-overridable so we can run against `php artisan serve` (no Herd)
        // by setting PLAYWRIGHT_BASE_URL=http://127.0.0.1:8000 — useful when
        // testing in CI / sandbox where the .test TLD doesn't resolve.
        baseURL: process.env.PLAYWRIGHT_BASE_URL || 'http://prose-peak-test.test',
        screenshot: 'only-on-failure',
        trace: 'on-first-retry',
        actionTimeout: 10000,
    },

    projects: [
        // Auth setup runs first — saves session state
        {
            name: 'setup',
            testMatch: /auth\.setup\.js/,
        },
        // All other tests reuse the authenticated session
        {
            name: 'tests',
            dependencies: ['setup'],
            use: {
                storageState: 'test-results/.auth/user.json',
            },
            testIgnore: /auth\.setup\.js/,
        },
    ],
});
