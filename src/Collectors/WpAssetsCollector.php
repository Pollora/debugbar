<?php

declare(strict_types=1);

namespace Pollora\Debugbar\Collectors;

use Illuminate\Contracts\Container\Container;
use Pollora\Asset\Application\Services\AssetManager;
use Pollora\Debugbar\Collector;
use Pollora\Debugbar\Origin;
use Pollora\Debugbar\Widget;

/**
 * The scripts, styles and script modules of the page, and where Pollora's
 * Vite containers serve them from.
 *
 * Read at the end of the request, from WordPress's own dependency objects:
 * what was printed, in the header or the footer, and what was enqueued with a
 * dependency nobody registered — which WordPress drops without a word.
 */
final class WpAssetsCollector extends Collector
{
    public function __construct(
        private readonly Container $container,
    ) {}

    public function getName(): string
    {
        return 'wp_assets';
    }

    public function title(): string
    {
        return 'WP Assets';
    }

    public function origin(): string
    {
        return Origin::WORDPRESS;
    }

    public function icon(): string
    {
        return 'file-code';
    }

    public function widget(): Widget
    {
        return Widget::Table;
    }

    public function position(): int
    {
        return 80;
    }

    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return ['printed' => 'Printed', 'source' => 'Source', 'dependencies' => 'Dependencies', 'note' => 'Note'];
    }

    /**
     * @return array<string, array{printed: string, source: string, dependencies: string, note: string}>
     */
    protected function data(): array
    {
        global $wp_scripts, $wp_styles;

        return [
            ...$this->vite(),
            ...$this->dependencies('script', $wp_scripts),
            ...$this->dependencies('style', $wp_styles),
            ...$this->modules(),
        ];
    }

    /**
     * @return array<string, array{printed: string, source: string, dependencies: string, note: string}>
     */
    private function dependencies(string $kind, mixed $dependencies): array
    {
        if (! $dependencies instanceof \WP_Dependencies) {
            return [];
        }

        $rows = [];
        $handles = array_unique([...$dependencies->queue, ...$dependencies->done]);

        foreach ($handles as $handle) {
            $asset = $dependencies->registered[$handle] ?? null;
            $deps = $asset instanceof \_WP_Dependency ? $asset->deps : [];
            $missing = array_filter($deps, static fn (string $dependency): bool => ! isset($dependencies->registered[$dependency]));
            $printed = in_array($handle, $dependencies->done, true);

            $rows["{$kind}: {$handle}"] = [
                'printed' => match (true) {
                    ! $printed => 'no',
                    $kind === 'script' && (int) $dependencies->get_data($handle, 'group') === 1 => 'footer',
                    default => 'header',
                },
                'source' => $asset instanceof \_WP_Dependency && is_string($asset->src) ? $this->shortUrl($asset->src) : '',
                'dependencies' => implode(', ', $deps),
                'note' => $missing !== [] ? 'missing: '.implode(', ', $missing) : '',
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, array{printed: string, source: string, dependencies: string, note: string}>
     */
    private function modules(): array
    {
        if (! function_exists('wp_script_modules')) {
            return [];
        }

        $modules = wp_script_modules();

        if (! method_exists($modules, 'get_queue')) {
            return [];
        }

        $rows = [];

        foreach ($modules->get_queue() as $id) {
            $module = method_exists($modules, 'get_registered') ? $modules->get_registered($id) : null;

            $rows["module: {$id}"] = $module === null
                ? ['printed' => '', 'source' => '', 'dependencies' => '', 'note' => 'not registered']
                : [
                    'printed' => $module['in_footer'] ? 'footer' : 'header',
                    'source' => $this->shortUrl($module['src']),
                    'dependencies' => implode(', ', array_column($module['dependencies'], 'id')),
                    'note' => '',
                ];
        }

        return $rows;
    }

    /**
     * Pollora's Vite containers: dev server or build.
     *
     * @return array<string, array{printed: string, source: string, dependencies: string, note: string}>
     */
    private function vite(): array
    {
        if (! $this->container->bound(AssetManager::class)) {
            return [];
        }

        $manager = $this->container->make(AssetManager::class);

        if (! method_exists($manager, 'containers')) {
            return [];
        }

        $rows = [];

        foreach ($manager->containers() as $name => $container) {
            $hot = is_file($container->getHotFile());
            $manifest = function_exists('public_path') ? public_path(trim($container->getBuildDirectory(), '/').'/'.$container->getManifestPath()) : '';

            $rows["vite: {$name}"] = [
                'printed' => '',
                'source' => $hot ? trim((string) file_get_contents($container->getHotFile())) : $this->shortUrl($container->getBuildDirectory()),
                'dependencies' => '',
                'note' => $hot ? 'dev server (hot file)' : ($manifest !== '' && is_file($manifest) ? 'build (manifest)' : 'no manifest: nothing built here'),
            ];
        }

        return $rows;
    }

    private function shortUrl(string $url): string
    {
        $home = function_exists('home_url') ? rtrim(home_url(), '/') : '';

        return $home !== '' && str_starts_with($url, $home) ? substr($url, strlen($home)) : $url;
    }
}
