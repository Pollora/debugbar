<?php

declare(strict_types=1);

use Pollora\Debugbar\DebugbarServiceProvider;
use Pollora\Debugbar\Recording\RequestRecorder;
use Pollora\WordPress\Events\WordPressBooting;

it('merges its config, so the tabs have defaults before it is published', function (): void {
    expect(config('debugbar-pollora.collectors.wp_queries'))->toBeTrue()
        ->and(config('debugbar-pollora.options.wp_hooks.count_filters'))->toBeFalse();
});

it('installs nothing for WordPress when it does not run (console, here)', function (): void {
    event(new WordPressBooting(lightweight: false));

    expect(app(RequestRecorder::class)->isInstalled())->toBeFalse();
});

it('installs the recorder once WordPress announces itself, when it runs', function (): void {
    (new ReflectionProperty($this->app, 'isRunningInConsole'))->setValue($this->app, false);
    config(['app.debug' => true]);
    $this->app['env'] = 'local';

    // register() decides once, as it does on a real request
    (new DebugbarServiceProvider($this->app))->register();
    event(new WordPressBooting(lightweight: false));

    expect(app(RequestRecorder::class)->isInstalled())->toBeTrue()
        ->and(defined('SAVEQUERIES') && SAVEQUERIES)->toBeTrue();
});
