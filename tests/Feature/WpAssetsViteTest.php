<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use DebugBar\DataFormatter\DataFormatter;
use Illuminate\Container\Container;
use Pollora\Asset\Application\Services\AssetManager;
use Pollora\Debugbar\Collectors\WpAssetsCollector;

/**
 * Pollora's Vite containers: the public path comes from the Laravel application.
 */
function viteRows(Container $container): array
{
    Functions\when('home_url')->justReturn('https://site.test/');
    Functions\when('wp_script_modules')->justReturn(new stdClass);

    return (new WpAssetsCollector($container))->setDataFormatter(new DataFormatter)->collect()['data']['data'];
}

function viteContainer(string $hot, string $build = 'build', string $manifest = '.vite/manifest.json'): object
{
    return new class($hot, $build, $manifest)
    {
        public function __construct(private string $hot, private string $build, private string $manifest) {}

        public function getHotFile(): string
        {
            return $this->hot;
        }

        public function getBuildDirectory(): string
        {
            return $this->build;
        }

        public function getManifestPath(): string
        {
            return $this->manifest;
        }
    };
}

function containerWith(array $viteContainers): Container
{
    $manager = new class($viteContainers)
    {
        public function __construct(private array $containers) {}

        public function containers(): array
        {
            return $this->containers;
        }
    };
    $container = new Container;
    $container->instance(AssetManager::class, $manager);

    return $container;
}

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/pollora-debugbar-'.bin2hex(random_bytes(6));
    mkdir($this->dir.'/build/.vite', 0777, true);
});

afterEach(function (): void {
    array_map('unlink', glob($this->dir.'/build/.vite/*') ?: []);
    @unlink($this->dir.'/hot');
    @rmdir($this->dir.'/build/.vite');
    @rmdir($this->dir.'/build');
    @rmdir($this->dir);
});

it('says a container is served by the dev server when its hot file exists', function (): void {
    file_put_contents($this->dir.'/hot', "http://localhost:5173\n");

    $rows = viteRows(containerWith(['theme' => viteContainer($this->dir.'/hot')]));

    expect($rows['vite: theme'])->toMatchArray(['source' => 'http://localhost:5173', 'note' => 'dev server (hot file)']);
});

it('says a container is served from its build when the manifest exists', function (): void {
    $this->app->usePublicPath($this->dir);
    file_put_contents($this->dir.'/build/.vite/manifest.json', '{}');

    $rows = viteRows(containerWith(['theme' => viteContainer($this->dir.'/hot')]));

    expect($rows['vite: theme'])->toMatchArray(['source' => 'build', 'note' => 'build (manifest)']);
});

it('says nothing was built when neither the hot file nor the manifest exists', function (): void {
    $this->app->usePublicPath($this->dir);

    $rows = viteRows(containerWith(['theme' => viteContainer($this->dir.'/hot')]));

    expect($rows['vite: theme']['note'])->toBe('no manifest: nothing built here');
});

it('shows no Vite row when the asset manager cannot list its containers', function (): void {
    $container = new Container;
    $container->instance(AssetManager::class, new stdClass);

    expect(viteRows($container))->toBe([]);
});
