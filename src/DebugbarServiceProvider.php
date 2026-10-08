<?php

declare(strict_types=1);

namespace Pollora\Debugbar;

use Fruitcake\LaravelDebugbar\LaravelDebugbar;
use Illuminate\Support\ServiceProvider;
use Pollora\Debugbar\Bridges\MessageBridge;
use Pollora\Debugbar\Http\WordPressExitResponder;
use Pollora\Debugbar\Recording\RequestRecorder;
use Pollora\WordPress\Events\WordPressBooting;

/**
 * Puts Pollora and WordPress in Laravel Debugbar.
 *
 * WordPress loads while providers boot, so what must watch it from the start
 * is set up here, in register(): `SAVEQUERIES`, and a listener that installs
 * the recorder and the bridges once WordPress announces itself. The tabs are
 * added when the application has booted.
 */
final class DebugbarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/debugbar-pollora.php', 'debugbar-pollora');

        $this->app->singleton(RequestRecorder::class);
        $this->app->singleton(CollectorRegistrar::class);

        if (! Activation::shouldRun($this->app)) {
            return;
        }

        $config = $this->app->make('config');

        if ($config->get('debugbar-pollora.collectors.wp_queries', true) && ! defined('SAVEQUERIES')) {
            define('SAVEQUERIES', true);
        }

        $this->app->make('events')->listen(WordPressBooting::class, function () use ($config): void {
            $this->app->make(RequestRecorder::class)->install(
                countAllHooks: (bool) $config->get('debugbar-pollora.options.wp_hooks.count_filters', false),
            );

            if ($config->get('debugbar-pollora.collectors.bridges', true)) {
                (new MessageBridge($this->collectingDebugbar(...)))->install(
                    queryMonitor: (bool) $config->get('debugbar-pollora.options.bridges.query_monitor', true),
                );
            }

            (new WordPressExitResponder(
                $this->collectingDebugbar(...),
                fn (LaravelDebugbar $debugbar) => $this->app->make(CollectorRegistrar::class)->register($debugbar),
            ))->install();
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/debugbar-pollora.php' => $this->app->configPath('debugbar-pollora.php'),
        ], 'debugbar-pollora-config');

        if (! Activation::shouldRun($this->app)) {
            return;
        }

        $this->app->booted(function (): void {
            $debugbar = $this->collectingDebugbar();

            if ($debugbar instanceof LaravelDebugbar) {
                $this->app->make(CollectorRegistrar::class)->register($debugbar);
            }
        });
    }

    /**
     * The bar, when it is enabled and has booted; null otherwise.
     */
    private function collectingDebugbar(): ?LaravelDebugbar
    {
        if (! $this->app->bound(LaravelDebugbar::class)) {
            return null;
        }

        $debugbar = $this->app->make(LaravelDebugbar::class);

        return $debugbar->isEnabled() && $debugbar->isCollecting() ? $debugbar : null;
    }
}
