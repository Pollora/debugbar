import { mkdirSync } from 'node:fs';
import { request } from '@playwright/test';

/**
 * Logs the site's administrator in once and keeps the cookies for every test.
 *
 * The debug bar is reserved to users who hold a capability (`manage_options` by
 * default), so a visitor sees nothing: the tests that look at the bar must be
 * logged in. The account is the one the workflow created when it installed the
 * site; its password comes from the environment (`E2E_ADMIN_PASSWORD`).
 */
export default async function globalSetup(): Promise<void> {
    const homeUrl = process.env.E2E_HOME_URL ?? 'https://pollora-debugbar.ddev.site';
    const username = process.env.E2E_ADMIN_USER ?? 'admin';
    const password = process.env.E2E_ADMIN_PASSWORD;

    if (! password) {
        throw new Error('E2E_ADMIN_PASSWORD is not set: the debug bar is only shown to a logged-in administrator.');
    }

    // WordPress lives under /cms in a Pollora site: wp-login.php resolves there.
    const context = await request.newContext({ baseURL: `${homeUrl}/cms/`, ignoreHTTPSErrors: true });

    // The first visit sets the cookie WordPress checks to know the browser accepts cookies.
    await context.get('wp-login.php');

    const response = await context.post('wp-login.php', {
        form: { log: username, pwd: password, 'wp-submit': 'Log In', redirect_to: `${homeUrl}/cms/wp-admin/`, testcookie: '1' },
        maxRedirects: 0,
    });

    const cookies = (await context.storageState()).cookies;

    if (response.status() !== 302 || ! cookies.some((cookie) => cookie.name.startsWith('wordpress_logged_in'))) {
        throw new Error(`The administrator could not log in (HTTP ${response.status()}): check E2E_ADMIN_USER and E2E_ADMIN_PASSWORD.`);
    }

    mkdirSync('./.auth', { recursive: true });
    await context.storageState({ path: './.auth/admin.json' });
    await context.dispose();
}
