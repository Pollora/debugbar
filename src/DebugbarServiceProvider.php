<?php

declare(strict_types=1);

namespace Pollora\Debugbar;

use Fruitcake\LaravelDebugbar\LaravelDebugbar;
use Fruitcake\LaravelDebugbar\Middleware\DebugbarEnabled;
use Illuminate\Support\ServiceProvider;
use Pollora\BlockBinding\Domain\Events\BindingResolved;
use Pollora\Debugbar\Bridges\MessageBridge;
use Pollora\Debugbar\Http\AdminBarRenderer;
use Pollora\Debugbar\Http\DoctorController;
use Pollora\Debugbar\Http\WordPressExitResponder;
use Pollora\Debugbar\Recording\AsyncRecorder;
use Pollora\Debugbar\Recording\BlockRecorder;
use Pollora\Debugbar\Recording\CacheRecorder;
use Pollora\Debugbar\Recording\CapabilityRecorder;
use Pollora\Debugbar\Recording\HookTimer;
use Pollora\Debugbar\Recording\HttpRecorder;
use Pollora\Debugbar\Recording\LanguageRecorder;
use Pollora\Debugbar\Recording\QueryTracer;
use Pollora\Debugbar\Recording\RequestRecorder;
use Pollora\Debugbar\Recording\SiteRecorder;
use Pollora\Debugbar\Support\Components;
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
        $this->app->singleton(Components::class, fn (): Components => new Components);
        $this->app->singleton(QueryTracer::class, fn ($app): QueryTracer => new QueryTracer(
            (int) $app->make('config')->get('debugbar-pollora.options.wp_queries.soft_limit', 100),
            $app->make(Components::class),
        ));
        $this->app->singleton(HttpRecorder::class);
        $this->app->singleton(CacheRecorder::class);
        $this->app->singleton(LanguageRecorder::class);
        $this->app->singleton(CapabilityRecorder::class, fn ($app): CapabilityRecorder => new CapabilityRecorder((bool) $app->make('config')->get('debugbar-pollora.options.wp_capabilities.backtrace', false)));
        $this->app->singleton(BlockRecorder::class);
        $this->app->singleton(AsyncRecorder::class);
        $this->app->singleton(HookTimer::class);
        $this->app->singleton(SiteRecorder::class);

        if (! Activation::shouldRun($this->app)) {
            return;
        }

        $config = $this->app->make('config');

        if ($config->get('debugbar-pollora.collectors.wp_queries', true) && ! defined('SAVEQUERIES')) {
            define('SAVEQUERIES', true);
        }

        $on = static fn (string $collector): bool => (bool) $config->get("debugbar-pollora.collectors.{$collector}", true);

        $admin = $config->get('debugbar-pollora.admin.enabled', true) && AdminBarRenderer::isAdminPage();

        $this->app->booting(function () use ($config): void {
            // The Site Editor and the Customizer show the front end in an
            // iframe: the request is still stored, but a second bar inside
            // the canvas would cover the page being edited
            if (! $config->get('debugbar-pollora.iframes', false) && $this->app->bound('request') && Activation::isFramed($this->app->make('request'))) {
                $config->set('debugbar.inject', false);
            }
        });

        if ($admin) {
            // Debugbar adds its tabs while it boots; by then every provider has
            // registered and its config is merged
            $this->app->booting(function () use ($config): void {
                foreach ((array) $config->get('debugbar-pollora.admin.hidden_collectors', AdminBarRenderer::LARAVEL_ONLY_COLLECTORS) as $collector) {
                    $config->set("debugbar.collectors.{$collector}", false);
                }
            });
        }

        if ($on('wp_blocks') && class_exists(BindingResolved::class)) {
            $this->app->make('events')->listen(BindingResolved::class, function (BindingResolved $binding): void {
                $this->app->make(BlockRecorder::class)->recordBinding($binding);
            });
        }

        $this->app->make('events')->listen(WordPressBooting::class, function () use ($config, $on, $admin): void {
            $this->app->make(RequestRecorder::class)->install(
                countAllHooks: (bool) $config->get('debugbar-pollora.options.wp_hooks.count_filters', false),
            );

            $recorders = [
                QueryTracer::class => $on('wp_queries') && $config->get('debugbar-pollora.options.wp_queries.trace', true),
                HttpRecorder::class => $on('wp_http'),
                CacheRecorder::class => $on('wp_cache'),
                LanguageRecorder::class => $on('wp_languages'),
                CapabilityRecorder::class => $on('wp_capabilities'),
                BlockRecorder::class => $on('wp_blocks'),
                AsyncRecorder::class => $on('pollora'),
                HookTimer::class => $on('wp_hooks') && $config->get('debugbar-pollora.options.wp_hooks.timings', false),
                SiteRecorder::class => $on('wp_request'),
            ];

            foreach (array_keys(array_filter($recorders)) as $recorder) {
                $this->app->make($recorder)->install();
            }

            if ($config->get('debugbar-pollora.collectors.bridges', true)) {
                (new MessageBridge($this->collectingDebugbar(...)))->install(
                    queryMonitor: (bool) $config->get('debugbar-pollora.options.bridges.query_monitor', true),
                );
            }

            $prepare = function (LaravelDebugbar $debugbar): void {
                // WordPress can exit before the booted callbacks that index
                // route names have run, and Debugbar's collectors link to
                // its own named routes
                $this->app->make('router')->getRoutes()->refreshNameLookups();
                $this->app->make(CollectorRegistrar::class)->register($debugbar);
            };

            (new WordPressExitResponder($this->collectingDebugbar(...), $prepare))->install();

            if ($admin) {
                (new AdminBarRenderer($this->collectingDebugbar(...), $prepare))->install();
            }
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

        if ($this->app->make('config')->get('debugbar-pollora.collectors.doctor', true)) {
            $this->app->make('router')
                ->get(trim((string) $this->app->make('config')->get('debugbar.route_prefix', '_debugbar'), '/').'/pollora/doctor', DoctorController::class)
                ->middleware([...(array) $this->app->make('config')->get('debugbar.route_middleware', []), DebugbarEnabled::class])
                ->name('debugbar.pollora.doctor');
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
