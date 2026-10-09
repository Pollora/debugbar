<?php

declare(strict_types=1);

use Pollora\Debugbar\Support\Components;

function components(): Components
{
    return new Components([
        '/site/public/content/plugins/' => ['kind' => 'plugin', 'name' => null],
        '/site/public/content/mu-plugins/' => ['kind' => 'mu-plugin', 'name' => ''],
        '/site/themes/' => ['kind' => 'theme', 'name' => null],
        '/site/vendor/pollora/' => ['kind' => 'pollora', 'name' => ''],
        '/site/vendor/' => ['kind' => 'vendor', 'name' => null],
        '/site/app/' => ['kind' => 'app', 'name' => ''],
        '/site/public/cms/' => ['kind' => 'core', 'name' => ''],
    ]);
}

it('names the plugin, theme or package a file belongs to', function (): void {
    expect(components()->of('/site/public/content/plugins/woocommerce/includes/class-wc.php'))->toBe('plugin: woocommerce')
        ->and(components()->of('/site/public/content/plugins/hello.php'))->toBe('plugin: hello')
        ->and(components()->of('/site/themes/default/functions.php'))->toBe('theme: default')
        ->and(components()->of('/site/vendor/spatie/laravel-data/src/Data.php'))->toBe('vendor: spatie/laravel-data')
        ->and(components()->of('/site/vendor/pollora/framework/src/Foo.php'))->toBe('pollora')
        ->and(components()->of('/site/public/cms/wp-includes/option.php'))->toBe('core')
        ->and(components()->of('/elsewhere/file.php'))->toBe('unknown');
});

it("gives a backtrace to the project's own code it passes through", function (): void {
    $trace = [
        ['file' => '/site/public/cms/wp-includes/option.php'],
        ['file' => '/site/vendor/pollora/framework/src/Foo.php'],
        ['file' => '/site/public/content/plugins/woocommerce/includes/class-wc.php'],
        ['file' => '/site/public/cms/wp-settings.php'],
    ];

    expect(components()->ofTrace($trace))->toBe('plugin: woocommerce');
});

it('gives a backtrace through no project code to its innermost frame', function (): void {
    $trace = [
        ['file' => '/site/public/cms/wp-includes/option.php'],
        ['file' => '/site/vendor/pollora/framework/src/WordPress/Bootstrap.php'],
    ];

    expect(components()->ofTrace($trace))->toBe('core');
});

it('prefers a plugin to a theme when both are in the trace', function (): void {
    expect(components()->ofTrace([
        ['file' => '/site/themes/default/functions.php'],
        ['file' => '/site/public/content/plugins/acme/acme.php'],
    ]))->toBe('plugin: acme');
});

it('keeps looking for directories until WordPress has defined where plugins live', function (): void {
    // No directories given and no WordPress here: nothing can be known, and nothing is kept
    $components = new Components;

    expect($components->of('/anywhere/plugins/acme/acme.php'))->toBe('unknown')
        ->and((new ReflectionProperty(Components::class, 'directories'))->getValue($components))->toBeNull();
});
