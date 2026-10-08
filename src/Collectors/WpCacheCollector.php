<?php

declare(strict_types=1);

namespace Pollora\Debugbar\Collectors;

use Pollora\Debugbar\Collector;
use Pollora\Debugbar\Origin;
use Pollora\Debugbar\Recording\CacheRecorder;
use Pollora\Debugbar\Support\Components;

/**
 * WordPress's object cache and the transients set during the request.
 *
 * Laravel's own cache keeps Debugbar's Cache tab.
 */
final class WpCacheCollector extends Collector
{
    public function __construct(
        private readonly CacheRecorder $recorder,
        private readonly Components $components,
    ) {}

    public function getName(): string
    {
        return 'wp_cache';
    }

    public function title(): string
    {
        return 'WP Cache';
    }

    public function origin(): string
    {
        return Origin::WORDPRESS;
    }

    public function icon(): string
    {
        return 'bolt';
    }

    public function position(): int
    {
        return 50;
    }

    /**
     * @return array<string, mixed>
     */
    protected function data(): array
    {
        global $wp_object_cache;

        $hits = is_object($wp_object_cache) && isset($wp_object_cache->cache_hits) ? (int) $wp_object_cache->cache_hits : null;
        $misses = is_object($wp_object_cache) && isset($wp_object_cache->cache_misses) ? (int) $wp_object_cache->cache_misses : null;

        $transients = [];

        foreach ($this->recorder->transients() as $transient) {
            $transients[] = sprintf(
                '%s%s · %s · %d bytes · %s',
                $transient['network'] ? 'site: ' : '',
                $transient['name'],
                $transient['expiration'] > 0 ? "expires in {$transient['expiration']} s" : 'no expiration',
                $transient['size'],
                $this->components->ofTrace($transient['frames']),
            );
        }

        return [
            'Object cache' => match (true) {
                ! is_object($wp_object_cache) => null,
                function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache() => 'persistent ('.$wp_object_cache::class.')',
                default => 'in memory for this request only ('.$wp_object_cache::class.')',
            },
            'Hits' => $hits,
            'Misses' => $misses,
            'Hit ratio' => $hits !== null && $misses !== null && $hits + $misses > 0 ? sprintf('%.1f %%', $hits / ($hits + $misses) * 100) : null,
            'Transients set' => $transients === [] ? 'none' : $transients,
            'OPcache' => $this->opcache(),
        ];
    }

    private function opcache(): string
    {
        if (! function_exists('opcache_get_status')) {
            return 'not installed';
        }

        $status = @opcache_get_status(false);

        if (! is_array($status) || ! ($status['opcache_enabled'] ?? false)) {
            return 'off';
        }

        $statistics = $status['opcache_statistics'] ?? [];

        return sprintf('on, %.1f %% hits', (float) ($statistics['opcache_hit_rate'] ?? 0));
    }
}
