<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Pollora\Debugbar\Activation;

/**
 * Testbench runs in the console, where nothing should run; these tests make
 * the application believe it serves a web request.
 */
function servingTheWeb(Application $app): void
{
    (new ReflectionProperty($app, 'isRunningInConsole'))->setValue($app, false);
}

beforeEach(function (): void {
    config(['app.debug' => true, 'debugbar.enabled' => null]);
    $this->app['env'] = 'local';
});

it('runs where Laravel Debugbar would be enabled', function (): void {
    servingTheWeb($this->app);

    expect(Activation::shouldRun($this->app))->toBeTrue();
});

it('never runs in the console', function (): void {
    expect(Activation::shouldRun($this->app))->toBeFalse();
});

it('never runs in production, as Debugbar itself refuses to', function (): void {
    servingTheWeb($this->app);
    $this->app['env'] = 'production';

    expect(Activation::shouldRun($this->app))->toBeFalse();
});

it("follows Debugbar's own switch over APP_DEBUG", function (): void {
    servingTheWeb($this->app);
    config(['debugbar.enabled' => false]);

    expect(Activation::shouldRun($this->app))->toBeFalse();
});

it("can be turned off while keeping Debugbar's other tabs", function (): void {
    servingTheWeb($this->app);
    config(['debugbar-pollora.enabled' => false]);

    expect(Activation::shouldRun($this->app))->toBeFalse();
});
