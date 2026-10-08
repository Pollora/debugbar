import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { defineConfig, devices } from '@playwright/test';

/**
 * Browser tests run against a real Pollora site with pollora/debugbar installed
 * and the fixtures in ./fixtures copied to its mu-plugins.
 *
 * E2E_HOME_URL   the site's front end, e.g. https://pollora-debugbar.ddev.site
 */
const homeUrl = process.env.E2E_HOME_URL ?? 'https://pollora-debugbar.ddev.site';

// DDEV serves HTTPS with a mkcert certificate: trust its authority when present.
if (! process.env.NODE_EXTRA_CA_CERTS) {
    try {
        const rootCa = `${execFileSync('mkcert', ['-CAROOT'], { encoding: 'utf8' }).trim()}/rootCA.pem`;

        if (existsSync(rootCa)) {
            process.env.NODE_EXTRA_CA_CERTS = rootCa;
        }
    } catch {
        // No mkcert: the site's certificate must already be trusted.
    }
}

export default defineConfig({
    testDir: './specs',
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: 1,
    reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
    outputDir: './test-results',
    use: {
        baseURL: `${homeUrl}/`,
        ignoreHTTPSErrors: true,
        trace: 'retain-on-failure',
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
