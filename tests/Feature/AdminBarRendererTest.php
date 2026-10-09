<?php

declare(strict_types=1);

use Pollora\Debugbar\Http\AdminBarRenderer;

it('prints the bar once on an admin page, after adding the tabs, and stores the request', function (): void {
    // Debugbar loads its routes only where it can be enabled, which a test is not
    $router = $this->app->make('router');
    $router->get('_debugbar/assets', fn (): string => '')->name('debugbar.assets');
    $router->get('_debugbar/open', fn (): string => '')->name('debugbar.openhandler');
    $router->getRoutes()->refreshNameLookups();
    $debugbar = $this->collectingDebugbar();
    $prepared = 0;
    $renderer = new AdminBarRenderer(fn () => $debugbar, function () use (&$prepared): void {
        $prepared++;
    }, fn (): bool => true);

    ob_start();
    $renderer->render();
    $renderer->render();
    $output = (string) ob_get_clean();

    expect($prepared)->toBe(1)
        ->and(substr_count($output, 'Laravel Debugbar Widget'))->toBe(1)
        ->and($debugbar->getStorage()->saved)->toHaveKey($debugbar->getCurrentRequestId());
});

it('prints nothing outside admin pages', function (): void {
    $debugbar = $this->collectingDebugbar();

    ob_start();
    (new AdminBarRenderer(fn () => $debugbar, fn () => null, fn (): bool => false))->render();

    expect((string) ob_get_clean())->toBe('');
});

it('prints nothing when the bar is not collecting', function (): void {
    ob_start();
    (new AdminBarRenderer(fn () => null, fn () => null, fn (): bool => true))->render();

    expect((string) ob_get_clean())->toBe('');
});
