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

it('says "unknown" for a file with no name, and answers the same file the same way twice', function (): void {
    expect(components()->of(''))->toBe('unknown');

    $components = components();

    expect($components->of('/site/themes/default/functions.php'))->toBe('theme: default')
        ->and($components->of('/site/themes/default/functions.php'))->toBe('theme: default');
});

it('skips frames without a file or from the debug bar itself when it names the owner of a backtrace', function (): void {
    $owner = components()->ofTrace([
        ['file' => ''],
        [],
        ['file' => '/site/vendor/pollora/debugbar/src/Recording/QueryTracer.php'],
        ['file' => '/site/themes/default/functions.php'],
    ]);

    expect($owner)->toBe('theme: default')
        ->and(components()->ofTrace([]))->toBe('unknown');
});

function probeComponents(bool $defined, string ...$files): array
{
    $output = shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../../Fixtures/components-probe.php').' '.($defined ? 'yes' : 'no').' '.implode(' ', array_map(escapeshellarg(...), $files)));

    return json_decode((string) $output, true, 512, JSON_THROW_ON_ERROR);
}

it('finds the directories WordPress defines, the longest first, and keeps them once it has defined them', function (): void {
    $probe = probeComponents(
        true,
        '/wp/content/plugins/acme/acme.php',
        '/wp/content/plugins/hello.php',
        '/wp/content/mu-plugins/loader.php',
        '/wp/content/themes/default/functions.php',
        '/wp/cms/wp-includes/option.php',
        '/wp/elsewhere.php',
    );

    expect($probe['answers'])->toBe([
        '/wp/content/plugins/acme/acme.php' => 'plugin: acme',
        '/wp/content/plugins/hello.php' => 'plugin: hello',
        '/wp/content/mu-plugins/loader.php' => 'mu-plugin',
        '/wp/content/themes/default/functions.php' => 'theme: default',
        '/wp/cms/wp-includes/option.php' => 'core',
        '/wp/elsewhere.php' => 'unknown',
    ])
        ->and($probe['kept'])->toBe([
            '/wp/content/mu-plugins/',
            '/wp/content/plugins/',
            '/wp/content/themes/',
            '/wp/cms/',
        ]);
});

it('knows no directory, and keeps none, before WordPress has defined any', function (): void {
    $probe = probeComponents(false, '/wp/content/plugins/acme/acme.php');

    expect($probe['answers'])->toBe(['/wp/content/plugins/acme/acme.php' => 'unknown'])
        ->and($probe['kept'])->toBeNull();
});

it('prefers the longest directory, whatever order they are given in', function (): void {
    $components = new Components([
        '/site/vendor/' => ['kind' => 'vendor', 'name' => null],
        '/site/vendor/pollora/' => ['kind' => 'pollora', 'name' => ''],
    ]);

    expect($components->of('/site/vendor/pollora/framework/src/Foo.php'))->toBe('pollora');
});

it('gives a backtrace to the innermost frame of the most responsible kind', function (): void {
    expect(components()->ofTrace([
        ['file' => '/site/public/content/plugins/first/a.php'],
        ['file' => '/site/public/content/plugins/second/b.php'],
    ]))->toBe('plugin: first')
        ->and(components()->ofTrace([
            ['file' => '/site/app/Foo.php'],
            ['file' => '/site/public/cms/wp-includes/option.php'],
            ['file' => '/site/themes/default/functions.php'],
        ]))->toBe('theme: default');
});

it('reads a Windows path, and names a theme file by its whole name when it sits at the root', function (): void {
    expect(components()->of('\\site\\themes\\default\\functions.php'))->toBe('theme: default')
        ->and(components()->of('/site/themes/functions.php'))->toBe('theme: functions.php');
});

it('labels an owner with no name by its kind, and one with a name by both', function (): void {
    $components = new Components(['/x/' => ['kind' => 'app', 'name' => null]]);

    expect($components->of('/x'.'/'))->toBe('app')
        ->and((new Components(['/x/' => ['kind' => 'app', 'name' => 'ab']]))->of('/x/y.php'))->toBe('app: ab');
});
