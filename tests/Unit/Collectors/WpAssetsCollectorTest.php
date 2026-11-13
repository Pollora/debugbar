<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use DebugBar\DataFormatter\DataFormatter;
use Illuminate\Container\Container;
use Pollora\Debugbar\Collectors\WpAssetsCollector;

require_once __DIR__.'/../../Fixtures/wordpress.php';

beforeEach(function (): void {
    $GLOBALS['wp_scripts'] = new WP_Dependencies;
    $GLOBALS['wp_styles'] = new WP_Dependencies;
    Functions\when('home_url')->justReturn('https://site.test/');
    // Brain Monkey keeps a function defined once a test has mocked it
    Functions\when('wp_script_modules')->justReturn(new stdClass);
});

afterEach(function (): void {
    unset($GLOBALS['wp_scripts'], $GLOBALS['wp_styles']);
});

function assetRows(?Container $container = null): array
{
    return (new WpAssetsCollector($container ?? new Container))->setDataFormatter(new DataFormatter)->collect()['data']['data'];
}

function enqueue(WP_Dependencies $dependencies, string $handle, string $src, array $deps = [], bool $printed = false, ?int $group = null): void
{
    $dependency = new _WP_Dependency($handle, $src, $deps, false, null);

    if ($group !== null) {
        $dependency->extra['group'] = $group;
    }

    $dependencies->registered[$handle] = $dependency;
    $dependencies->queue[] = $handle;

    if ($printed) {
        $dependencies->done[] = $handle;
    }
}

it('says where each script was printed, with a path relative to the site', function (): void {
    enqueue($GLOBALS['wp_scripts'], 'app', 'https://site.test/content/app.js', printed: true);
    enqueue($GLOBALS['wp_scripts'], 'late', 'https://cdn.test/late.js', printed: true, group: 1);
    enqueue($GLOBALS['wp_scripts'], 'unused', '/content/unused.js');

    $rows = assetRows();

    expect($rows['script: app'])->toMatchArray(['printed' => 'header', 'source' => '/content/app.js'])
        ->and($rows['script: late'])->toMatchArray(['printed' => 'footer', 'source' => 'https://cdn.test/late.js'])
        ->and($rows['script: unused']['printed'])->toBe('no');
});

it('reads styles from the styles queue, which has no footer', function (): void {
    enqueue($GLOBALS['wp_styles'], 'theme', 'https://site.test/content/theme.css', printed: true, group: 1);

    expect(assetRows()['style: theme']['printed'])->toBe('header');
});

it('names the dependencies nobody registered, which WordPress drops without a word', function (): void {
    enqueue($GLOBALS['wp_scripts'], 'jquery', 'https://site.test/jquery.js');
    enqueue($GLOBALS['wp_scripts'], 'app', 'https://site.test/app.js', ['jquery', 'ghost', 'phantom']);

    $row = assetRows()['script: app'];

    expect($row['dependencies'])->toBe('jquery, ghost, phantom')
        ->and($row['note'])->toBe('missing: ghost, phantom')
        ->and(assetRows()['script: jquery']['note'])->toBe('');
});

it('lists a handle that is both queued and printed once, and one with no source as empty', function (): void {
    enqueue($GLOBALS['wp_scripts'], 'inline', '', printed: true);
    $GLOBALS['wp_scripts']->registered['inline']->src = false;

    $rows = assetRows();

    expect(array_keys($rows))->toBe(['script: inline'])
        ->and($rows['script: inline']['source'])->toBe('');
});

it('shows nothing for scripts and styles before WordPress created their queues', function (): void {
    unset($GLOBALS['wp_scripts'], $GLOBALS['wp_styles']);

    expect(assetRows())->toBe([]);
});

it('lists the script modules, with the ones nobody registered', function (): void {
    $modules = new class
    {
        public function get_queue(): array
        {
            return ['@acme/view', '@acme/ghost'];
        }

        public function get_registered(string $id): ?array
        {
            return $id === '@acme/view'
                ? ['src' => 'https://site.test/content/view.js', 'in_footer' => true, 'dependencies' => [['id' => '@wordpress/interactivity'], ['id' => '@acme/base']]]
                : null;
        }
    };
    Functions\when('wp_script_modules')->justReturn($modules);

    $rows = assetRows();

    expect($rows['module: @acme/view'])->toBe(['printed' => 'footer', 'source' => '/content/view.js', 'dependencies' => '@wordpress/interactivity, @acme/base', 'note' => ''])
        ->and($rows['module: @acme/ghost']['note'])->toBe('not registered');
});

it('lists the modules of a registry that only knows its queue', function (): void {
    Functions\when('wp_script_modules')->justReturn(new class
    {
        public function get_queue(): array
        {
            return ['@acme/solo'];
        }
    });

    expect(assetRows()['module: @acme/solo']['note'])->toBe('not registered');
});

it('skips the modules when the registry cannot tell its queue', function (): void {
    Functions\when('wp_script_modules')->justReturn(new stdClass);

    expect(assetRows())->toBe([]);
});
