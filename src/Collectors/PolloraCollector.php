<?php

declare(strict_types=1);

namespace Pollora\Debugbar\Collectors;

use Composer\InstalledVersions;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Route;
use Pollora\Debugbar\Collector;
use Pollora\Debugbar\Origin;
use Pollora\Debugbar\Recording\AsyncRecorder;
use Pollora\Discovery\Application\Services\DiscoveryManager;
use Pollora\Hook\Infrastructure\Services\AsyncInspector;
use Pollora\Modules\Application\Services\ModuleStates;
use Pollora\Route\Application\Services\AnsweringTemplate;
use Pollora\Route\Domain\Contracts\WordPressRouteInterface;
use Pollora\Route\Domain\Models\TemplateResolution;

/**
 * What only Pollora knows about the request: what answered it, and the state
 * of discovery, modules, the theme and async actions.
 *
 * Each part is read on its own and fails on its own: a tab that disappears
 * because one service is missing tells the developer less than one line that
 * says so.
 */
final class PolloraCollector extends Collector
{
    public function __construct(
        private readonly Container $container,
        private readonly ?AsyncRecorder $async = null,
    ) {}

    public function getName(): string
    {
        return 'pollora';
    }

    public function title(): string
    {
        return 'Pollora';
    }

    public function origin(): string
    {
        return Origin::POLLORA;
    }

    public function icon(): string
    {
        return 'leaf';
    }

    /**
     * @return array<string, mixed>
     */
    protected function data(): array
    {
        return [
            'Answered by' => $this->safely($this->answeredBy(...)),
            'Template' => $this->safely($this->template(...)),
            'Versions' => $this->safely($this->versions(...)),
            'Discovery' => $this->safely($this->discovery(...)),
            'Modules' => $this->safely($this->modules(...)),
            'Theme' => $this->safely($this->theme(...)),
            'Async actions' => $this->safely($this->asyncActions(...)),
            'Async dispatched' => $this->safely($this->asyncDispatched(...)),
            'WordPress' => $this->safely($this->wordpress(...)),
        ];
    }

    /**
     * The route, the hierarchy, or WordPress on its own (REST, admin-ajax).
     */
    private function answeredBy(): string
    {
        if ($this->resolution() instanceof TemplateResolution) {
            return 'Template hierarchy (catch-all route)';
        }

        $route = $this->container->bound('router') ? $this->container->make('router')->current() : null;

        if ($route instanceof Route) {
            if ($route instanceof WordPressRouteInterface && $route->isWordPressRoute() && $route->hasCondition()) {
                return sprintf(
                    'Route::wp(%s) → %s',
                    implode(', ', array_map(static fn (mixed $value): string => var_export($value, true), [$route->getCondition(), ...$route->getConditionParameters()])),
                    $route->getActionName(),
                );
            }

            return sprintf('%s %s → %s', implode('|', $route->methods()), $route->uri(), $route->getActionName());
        }

        return match (true) {
            defined('REST_REQUEST') && REST_REQUEST => 'WordPress REST API',
            function_exists('wp_doing_ajax') && wp_doing_ajax() => 'WordPress admin-ajax',
            function_exists('is_admin') && is_admin() => 'WordPress admin',
            default => 'WordPress, outside any Laravel route',
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function template(): ?array
    {
        $resolution = $this->resolution();

        if (! $resolution instanceof TemplateResolution) {
            return null;
        }

        return [
            'file' => $this->relativePath($resolution->template),
            'view' => $resolution->view,
            'condition' => $resolution->condition,
            'index fallback' => $resolution->usedIndexFallback,
            'outcome' => $resolution->outcome->value,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function versions(): array
    {
        global $wp_version;

        return [
            'pollora/framework' => InstalledVersions::isInstalled('pollora/framework') ? InstalledVersions::getPrettyVersion('pollora/framework') : null,
            'pollora/hook' => InstalledVersions::isInstalled('pollora/hook') ? InstalledVersions::getPrettyVersion('pollora/hook') : null,
            'laravel' => $this->container instanceof Application ? $this->container->version() : null,
            'wordpress' => is_string($wp_version ?? null) ? $wp_version : null,
            'php' => PHP_VERSION,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function discovery(): ?array
    {
        if (! $this->container->bound(DiscoveryManager::class)) {
            return null;
        }

        $engine = $this->container->make(DiscoveryManager::class)->getEngine();

        if (! method_exists($engine, 'getCacheManager')) {
            return null;
        }

        $cache = $engine->getCacheManager();
        $scans = method_exists($cache, 'scans') ? $cache->scans() : [];

        return [
            'persistent cache' => $cache->isCacheEnabled() ? 'on' : 'off (debug mode)',
            'time' => sprintf('%.1f ms', array_sum(array_column($scans, 'milliseconds'))),
            'locations' => array_map(fn (array $scan): string => sprintf(
                '%s · %s · %d structures · %.1f ms',
                $this->relativePath($scan['path']),
                $scan['source'],
                $scan['structures'],
                $scan['milliseconds'],
            ), $scans),
        ];
    }

    /**
     * @return array<string, string>|null
     */
    private function modules(): ?array
    {
        if (! $this->container->bound(ModuleStates::class)) {
            return null;
        }

        $states = $this->container->make(ModuleStates::class);

        if (! $states->available()) {
            return null;
        }

        $modules = [];

        foreach ($states->all() as $module) {
            $modules[(string) $module['name']] = ($module['enabled'] ? 'enabled' : 'disabled').($module['locked'] ? ', locked' : '');
        }

        return $modules;
    }

    /**
     * @return array<string, string>|null
     */
    private function theme(): ?array
    {
        if (! function_exists('wp_get_theme')) {
            return null;
        }

        $theme = wp_get_theme();

        return [
            'name' => (string) $theme->get('Name'),
            'version' => (string) $theme->get('Version'),
            'stylesheet' => $theme->get_stylesheet(),
            'template' => $theme->get_template(),
        ];
    }

    /**
     * @return list<string>|null
     */
    private function asyncActions(): ?array
    {
        if (! class_exists(AsyncInspector::class)) {
            return null;
        }

        $registrations = $this->container->make(AsyncInspector::class)->registrations();

        return array_map(
            static fn (object $handler): string => sprintf('%s → %s', $handler->hook, WpHooksCollector::describe($handler->handler)),
            $registrations,
        ) ?: null;
    }

    /**
     * What this request queued, with `pollora/hook` 1.5+.
     *
     * @return list<string>|null
     */
    private function asyncDispatched(): ?array
    {
        $dispatched = $this->async?->dispatched() ?? [];

        return array_map(
            static fn (array $item): string => sprintf('%s → %s · %s%s', $item['hook'], $item['handler'], $item['driver'] !== '' ? $item['driver'] : 'default driver', $item['delay'] > 0 ? " · in {$item['delay']} s" : ''),
            $dispatched,
        ) ?: null;
    }

    /**
     * The constants and drop-ins that change how WordPress behaves.
     *
     * @return array<string, mixed>
     */
    private function wordpress(): array
    {
        $constants = [];

        foreach (['WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'SCRIPT_DEBUG', 'SAVEQUERIES', 'WP_CACHE', 'DISABLE_WP_CRON', 'CONCATENATE_SCRIPTS'] as $constant) {
            $constants[$constant] = defined($constant) ? constant($constant) : 'undefined';
        }

        $dropIns = [];

        if (defined('WP_CONTENT_DIR')) {
            foreach (['db.php', 'object-cache.php', 'advanced-cache.php', 'maintenance.php', 'sunrise.php'] as $dropIn) {
                if (is_file(WP_CONTENT_DIR.'/'.$dropIn)) {
                    $dropIns[] = $dropIn;
                }
            }
        }

        global $wpdb;

        return [
            'environment' => function_exists('wp_get_environment_type') ? wp_get_environment_type() : null,
            'constants' => $constants,
            'drop-ins' => $dropIns === [] ? 'none' : implode(', ', $dropIns),
            'database server' => is_object($wpdb) && method_exists($wpdb, 'db_server_info') ? (string) $wpdb->db_server_info() : null,
            'memory limit' => sprintf(
                'PHP %s, WordPress %s',
                (string) ini_get('memory_limit'),
                defined('WP_MEMORY_LIMIT') ? (string) WP_MEMORY_LIMIT : '—',
            ),
            'web server' => is_string($_SERVER['SERVER_SOFTWARE'] ?? null) ? $_SERVER['SERVER_SOFTWARE'] : null,
        ];
    }

    private function resolution(): ?TemplateResolution
    {
        return $this->container->bound(AnsweringTemplate::class)
            ? $this->container->make(AnsweringTemplate::class)->resolution()
            : null;
    }

    /**
     * @param  \Closure(): mixed  $read
     */
    private function safely(\Closure $read): mixed
    {
        try {
            return $read();
        } catch (\Throwable $throwable) {
            return 'unavailable: '.$throwable->getMessage();
        }
    }
}
