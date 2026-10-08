<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use DebugBar\DataFormatter\DataFormatter;
use Pollora\Debugbar\Collectors\WpRequestCollector;
use Pollora\Debugbar\Recording\RequestRecorder;
use Pollora\Debugbar\Recording\SiteRecorder;
use Pollora\Debugbar\Support\Components;

if (! class_exists(WP_Screen::class)) {
    class WP_Screen
    {
        public string $id = 'edit-post';

        public string $base = 'edit';

        public string $post_type = 'post';

        public string $taxonomy = '';

        public function is_block_editor(): bool
        {
            return false;
        }
    }
}

function requestDataWith(?SiteRecorder $sites = null): array
{
    return (new WpRequestCollector(new RequestRecorder, $sites, new Components([])))
        ->setDataFormatter(new DataFormatter)
        ->collect()['data'];
}

afterEach(function (): void {
    unset($GLOBALS['pagenow'], $GLOBALS['hook_suffix'], $GLOBALS['current_site'], $GLOBALS['_wp_switched_stack']);
});

it('shows the admin page and screen in wp-admin', function (): void {
    $GLOBALS['pagenow'] = 'edit.php';
    $GLOBALS['hook_suffix'] = 'edit.php';
    Functions\when('is_admin')->justReturn(true);
    Functions\when('get_current_screen')->justReturn(new WP_Screen);
    Functions\when('get_queried_object')->justReturn(null);

    $data = requestDataWith();

    expect($data['Admin page'])->toBe('edit.php')
        ->and($data['Screen'])->toContain('edit-post')
        ->and($data)->not->toHaveKey('Site');
});

it('shows the site and every switch between sites, and a switch never restored', function (): void {
    $switch = null;
    Actions\expectAdded('switch_blog')->once()->whenHappen(function (callable $callback) use (&$switch): void {
        $switch = $callback;
    });
    $sites = new SiteRecorder;
    $sites->install();
    $switch(2, 1, 'switch');
    $switch(1, 2, 'restore');
    $switch(3, 1, 'switch');

    $GLOBALS['current_site'] = (object) ['id' => 1];
    $GLOBALS['_wp_switched_stack'] = [1];
    Functions\when('get_queried_object')->justReturn(null);
    Functions\when('is_multisite')->justReturn(true);
    Functions\when('get_current_blog_id')->justReturn(3);
    Functions\when('is_main_site')->alias(fn (int $site): bool => $site === 1);
    Functions\when('get_current_network_id')->justReturn(1);
    Functions\when('ms_is_switched')->justReturn(true);

    $data = requestDataWith($sites);

    expect($data['Site'])->toBe('#1 (main site)')
        ->and($data['Still switched'])->toStartWith('yes, to #3')
        ->and($data['Site switches'])->toContain('switch #1 → #2')
        ->and($data['Site switches'])->toContain('restore #2 → #1');
});
