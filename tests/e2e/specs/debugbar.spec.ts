import { expect, test, type Page } from '@playwright/test';

/**
 * The debug bar on a real Pollora page: Pollora's and WordPress's tabs, a REST
 * call made from the page, and the data third parties add.
 */

type DebugBar = {
    controls: Record<string, unknown>;
    datasets: Record<string, Record<string, any>>;
};

async function debugbar(page: Page): Promise<DebugBar> {
    await page.waitForFunction(() => 'phpdebugbar' in window && Object.keys((window as any).phpdebugbar.datasets).length > 0);

    return page.evaluate(() => {
        const bar = (window as any).phpdebugbar;

        return { controls: Object.fromEntries(Object.keys(bar.controls).map((name) => [name, true])), datasets: bar.datasets };
    });
}

function firstDataset(bar: DebugBar): Record<string, any> {
    return Object.values(bar.datasets)[0];
}

test('puts the Pollora tab, then the WordPress tabs, after Laravel’s', async ({ page }) => {
    await page.goto('');
    const names = Object.keys((await debugbar(page)).controls);

    const pollora = names.indexOf('pollora');

    expect(pollora).toBeGreaterThan(names.indexOf('queries'));
    expect(names.indexOf('wp_request')).toBeGreaterThan(pollora);
    expect(names.indexOf('wp_queries')).toBeGreaterThan(pollora);
    expect(names.indexOf('wp_hooks')).toBeGreaterThan(pollora);
    expect(names.indexOf('acme_cart')).toBeGreaterThan(names.indexOf('wp_hooks'));
});

test('says the template hierarchy answered the front page, and with which view', async ({ page }) => {
    await page.goto('');
    const pollora = firstDataset(await debugbar(page)).pollora.data;

    expect(pollora['Answered by']).toBe('Template hierarchy (catch-all route)');
    expect(JSON.stringify(pollora.Template)).toContain('"view":"home"');

    await page.locator('.phpdebugbar-tab', { hasText: 'Pollora' }).click();
    await expect(page.locator('.phpdebugbar-panel.phpdebugbar-active')).toContainText('Answered by');
});

test('shows the queries WordPress ran and the hooks that fired', async ({ page }) => {
    await page.goto('');
    const data = firstDataset(await debugbar(page));

    expect(data.wp_queries.nb_statements).toBeGreaterThan(0);
    expect(data.wp_queries.statements[0].connection).toBe('wpdb');
    expect(data.wp_hooks.data.data.init.calls).toBeGreaterThan(0);
});

test('lists a REST call made from the page among the bar’s requests', async ({ page }) => {
    await page.goto('');
    await debugbar(page);

    const status = await page.evaluate(async () => (await fetch('/wp-json/wp/v2/posts')).status);
    expect(status).toBe(200);

    await expect.poll(async () => page.evaluate(
        () => Object.values((window as any).phpdebugbar.datasets).map((dataset: any) => dataset.__meta?.uri),
    )).toContain('/wp-json/wp/v2/posts');
});

test('shows what a plugin and a package add, labelled as theirs', async ({ page }) => {
    await page.goto('');
    const bar = await debugbar(page);
    const data = firstDataset(bar);

    expect(bar.controls).toHaveProperty('acme_cart');
    expect(bar.controls).toHaveProperty('acme_orders');
    expect(data.acme_cart.data.data.apple.qty).toBe(3);
    expect(data.wp_request.data['Acme › cart id']).toBe('c-42');
    expect(data.messages.messages.map((message: any) => message.message)).toEqual(
        expect.arrayContaining(['Acme cart c-42 rebuilt', 'Written for Query Monitor']),
    );
});
