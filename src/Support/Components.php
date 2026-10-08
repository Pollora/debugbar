<?php

declare(strict_types=1);

namespace Pollora\Debugbar\Support;

/**
 * Says who a file belongs to: WordPress core, a plugin, a theme, Pollora, a
 * module, the application or a Composer package.
 *
 * The same idea as Query Monitor's components, from the directories a
 * Pollora project actually has. A backtrace is attributed to the most
 * specific owner it passes through, so a query a plugin asks WordPress to
 * run is the plugin's, not core's.
 */
final class Components
{
    /**
     * The project's own code, most responsible first: when a backtrace passes
     * through one of these, it is the owner.
     */
    private const array PRIORITY = ['plugin', 'mu-plugin', 'theme', 'module', 'app'];

    /**
     * @var array<string, array{kind: string, name: string|null}>|null
     */
    private ?array $directories = null;

    /**
     * @var array<string, string>
     */
    private array $cache = [];

    /**
     * @param  array<string, array{kind: string, name: string|null}>|null  $directories  Directory => owner, longest first; detected when null
     */
    public function __construct(?array $directories = null)
    {
        if ($directories !== null) {
            uksort($directories, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
            $this->directories = $directories;
        }
    }

    /**
     * The owner of a file, as a label: `plugin: woocommerce`, `theme: default`, `core`…
     */
    public function of(string $file): string
    {
        if ($file === '') {
            return 'unknown';
        }

        if (isset($this->cache[$file])) {
            return $this->cache[$file];
        }

        $label = $this->label($this->owner($file));

        // Not kept while WordPress has not defined its directories yet: the
        // same file belongs to a plugin once it has
        if ($this->directories !== null) {
            $this->cache[$file] = $label;
        }

        return $label;
    }

    /**
     * The owner of a backtrace, innermost frame first.
     *
     * The project's own code wins (plugin, mu-plugin, theme, module, app), as
     * in Query Monitor; otherwise the innermost frame decides. A query
     * WordPress makes while Pollora boots it is core's, not Pollora's.
     *
     * @param  list<array{file?: string}>  $frames
     */
    public function ofTrace(array $frames): string
    {
        $best = null;
        $bestRank = PHP_INT_MAX;
        $innermost = null;

        foreach ($frames as $frame) {
            if (! isset($frame['file']) || $frame['file'] === '' || str_contains($frame['file'], '/pollora/debugbar/')) {
                continue;
            }

            $owner = $this->owner($frame['file']);
            $innermost ??= $owner;
            $rank = array_search($owner['kind'], self::PRIORITY, true);

            if ($rank !== false && $rank < $bestRank) {
                $best = $owner;
                $bestRank = $rank;
            }
        }

        $owner = $best ?? $innermost;

        return $owner === null ? 'unknown' : $this->label($owner);
    }

    /**
     * @return array{kind: string, name: string|null}
     */
    private function owner(string $file): array
    {
        $file = str_replace('\\', '/', $file);

        foreach ($this->directories() as $directory => $owner) {
            if (! str_starts_with($file, $directory)) {
                continue;
            }

            if ($owner['name'] !== null) {
                return $owner;
            }

            // Plugins, themes, modules and packages are named by their first directory
            $rest = substr($file, strlen($directory));
            $segments = explode('/', $rest);
            $name = $owner['kind'] === 'vendor' ? implode('/', array_slice($segments, 0, 2)) : $segments[0];

            return ['kind' => $owner['kind'], 'name' => $owner['kind'] === 'plugin' && ! str_contains($rest, '/') ? basename($name, '.php') : $name];
        }

        return ['kind' => 'unknown', 'name' => null];
    }

    /**
     * @param  array{kind: string, name: string|null}  $owner
     */
    private function label(array $owner): string
    {
        return $owner['name'] === null || $owner['name'] === '' ? $owner['kind'] : "{$owner['kind']}: {$owner['name']}";
    }

    /**
     * @return array<string, array{kind: string, name: string|null}>
     */
    private function directories(): array
    {
        if ($this->directories !== null) {
            return $this->directories;
        }

        $directories = [];
        $add = static function (?string $path, string $kind, ?string $name = null) use (&$directories): void {
            if ($path !== null && $path !== '') {
                $directories[rtrim(str_replace('\\', '/', $path), '/').'/'] = ['kind' => $kind, 'name' => $name];
            }
        };

        $add(defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : null, 'plugin');
        $add(defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : null, 'mu-plugin', '');

        if (function_exists('get_theme_root')) {
            $add(get_theme_root(), 'theme');
        }

        // Outside a Laravel application (a unit test), only WordPress's directories are known
        try {
            $add(base_path('themes'), 'theme');
            $add(base_path('Modules'), 'module');
            $add(base_path('vendor/pollora'), 'pollora', '');
            $add(base_path('vendor/laravel'), 'laravel', '');
            $add(base_path('vendor'), 'vendor');
            $add(app_path(), 'app', '');
        } catch (\Throwable) {
        }

        if (defined('ABSPATH')) {
            $add(ABSPATH, 'core', '');
        }

        uksort($directories, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        // The first queries run before WordPress defines where plugins and
        // themes live; keep looking until it has
        if (defined('WP_PLUGIN_DIR') && function_exists('get_theme_root')) {
            $this->directories = $directories;
        }

        return $directories;
    }
}
