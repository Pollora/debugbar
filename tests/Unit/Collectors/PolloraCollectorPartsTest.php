<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Illuminate\Container\Container;
use Pollora\Debugbar\Collectors\PolloraCollector;
use Pollora\Debugbar\Recording\AsyncRecorder;
use Pollora\Discovery\Application\Services\DiscoveryManager;
use Pollora\Discovery\Domain\Contracts\DiscoveryEngineInterface;
use Pollora\Discovery\Infrastructure\Services\DiscoveryEngine;
use Pollora\Hook\Infrastructure\Services\AsyncInspector;
use Pollora\Modules\Application\Services\ModuleStates;
use Pollora\Route\Application\Services\AnsweringTemplate;
use Pollora\Route\Domain\Enums\TemplateOutcome;
use Pollora\Route\Domain\Models\TemplateResolution;
use Pollora\Route\Infrastructure\Models\Route as PolloraRoute;

/**
 * The collector's parts, each from the service that feeds it, in a plain
 * container: no Laravel application, no WordPress.
 */
function partsOf(Container $container, ?AsyncRecorder $async = null): array
{
    $collector = new PolloraCollector($container, $async);

    // The raw values, before the bar's formatter turns them into text
    return (new ReflectionMethod($collector, 'data'))->invoke($collector);
}

function containerWithRoute(?object $route): Container
{
    $container = new Container;
    $container->instance('router', new class($route)
    {
        public function __construct(private ?object $route) {}

        public function current(): ?object
        {
            return $this->route;
        }
    });

    return $container;
}

it('names the WordPress condition a Route::wp() route answers to', function (): void {
    $route = new PolloraRoute(['GET'], 'archive', fn () => 'ok');
    $route->setIsWordPressRoute(true)->setCondition('is_post_type_archive')->setConditionParameters(['product', 3]);

    expect(partsOf(containerWithRoute($route))['Answered by'])->toMatch("/^Route::wp\\('is_post_type_archive', 'product', 3\\) → /");
});

it('names a route with a WordPress condition by its methods and URI when it has no condition', function (): void {
    $route = new PolloraRoute(['GET'], 'plain', fn () => 'ok');
    $route->setIsWordPressRoute(true);

    expect(partsOf(containerWithRoute($route))['Answered by'])->toBe('GET|HEAD plain → Closure');
});

describe('when no Laravel route answered', function (): void {
    beforeEach(function (): void {
        Functions\when('wp_doing_ajax')->justReturn(false);
        Functions\when('is_admin')->justReturn(false);
    });

    it('says wp-admin answered', function (): void {
        Functions\when('is_admin')->justReturn(true);

        expect(partsOf(containerWithRoute(null))['Answered by'])->toBe('WordPress admin');
    });

    it('says admin-ajax answered', function (): void {
        Functions\when('wp_doing_ajax')->justReturn(true);

        expect(partsOf(containerWithRoute(null))['Answered by'])->toBe('WordPress admin-ajax');
    });

    it('says WordPress answered alone when the container has no router', function (): void {
        expect(partsOf(new Container)['Answered by'])->toBe('WordPress, outside any Laravel route');
    });
});

describe('discovery', function (): void {
    function discoveryContainer(DiscoveryEngineInterface $engine): Container
    {
        $container = new Container;
        $container->instance(DiscoveryManager::class, new DiscoveryManager($engine));

        return $container;
    }

    it('says whether the cache is persistent and how long the scans took', function (): void {
        $discovery = partsOf(discoveryContainer(new DiscoveryEngine))['Discovery'];

        expect($discovery)->toHaveKeys(['persistent cache', 'time', 'locations'])
            ->and($discovery['persistent cache'])->toBeIn(['on', 'off (debug mode)'])
            ->and($discovery['time'])->toBe('0.0 ms')
            ->and($discovery['locations'])->toBe([]);
    });

    it('shows nothing when the engine has no cache manager', function (): void {
        expect(partsOf(discoveryContainer(Mockery::mock(DiscoveryEngineInterface::class)))['Discovery'])->toBeNull();
    });

    it('shows nothing when discovery is not bound', function (): void {
        expect(partsOf(new Container)['Discovery'])->toBeNull();
    });
});

describe('modules', function (): void {
    function moduleContainer(bool $available, array $modules = []): Container
    {
        $states = Mockery::mock(ModuleStates::class);
        $states->shouldReceive('available')->andReturn($available);
        $states->shouldReceive('all')->andReturn($modules);
        $container = new Container;
        $container->instance(ModuleStates::class, $states);

        return $container;
    }

    it('lists each module, enabled or not, and locked when it cannot be switched', function (): void {
        $modules = partsOf(moduleContainer(true, [
            ['name' => 'shop', 'enabled' => true, 'locked' => false],
            ['name' => 'blog', 'enabled' => false, 'locked' => false],
            ['name' => 'core', 'enabled' => true, 'locked' => true],
        ]))['Modules'];

        expect($modules)->toBe(['shop' => 'enabled', 'blog' => 'disabled', 'core' => 'enabled, locked']);
    });

    it('shows nothing while module states are not available', function (): void {
        expect(partsOf(moduleContainer(false))['Modules'])->toBeNull();
    });

    it('shows nothing when modules are not bound', function (): void {
        expect(partsOf(new Container)['Modules'])->toBeNull();
    });
});

it('names the active theme, its stylesheet and its parent template', function (): void {
    Functions\when('wp_get_theme')->justReturn(new class
    {
        public function get(string $header): string
        {
            return ['Name' => 'Acme', 'Version' => '1.2.0'][$header];
        }

        public function get_stylesheet(): string
        {
            return 'acme-child';
        }

        public function get_template(): string
        {
            return 'acme';
        }
    });

    expect(partsOf(new Container)['Theme'])->toBe(['name' => 'Acme', 'version' => '1.2.0', 'stylesheet' => 'acme-child', 'template' => 'acme']);
});

describe('async actions', function (): void {
    it('lists the async handlers registered, by hook', function (): void {
        $inspector = Mockery::mock(AsyncInspector::class);
        $inspector->shouldReceive('registrations')->andReturn([(object) ['hook' => 'save_post', 'handler' => 'sync_post']]);
        $container = new Container;
        $container->instance(AsyncInspector::class, $inspector);

        expect(partsOf($container)['Async actions'])->toBe(['save_post → sync_post']);
    });

    it('shows nothing when no handler is async', function (): void {
        $inspector = Mockery::mock(AsyncInspector::class);
        $inspector->shouldReceive('registrations')->andReturn([]);
        $container = new Container;
        $container->instance(AsyncInspector::class, $inspector);

        expect(partsOf($container)['Async actions'])->toBeNull();
    });

    it('lists what this request queued, with its driver and delay', function (): void {
        $async = new AsyncRecorder;
        (new ReflectionProperty($async, 'dispatched'))->setValue($async, [
            ['hook' => 'save_post', 'handler' => 'App\\Sync@handle', 'driver' => 'queue', 'delay' => 30],
            ['hook' => 'init', 'handler' => 'Closure', 'driver' => '', 'delay' => 0],
        ]);

        expect(partsOf(new Container, $async)['Async dispatched'])->toBe([
            'save_post → App\\Sync@handle · queue · in 30 s',
            'init → Closure · default driver',
        ]);
    });

    it('shows nothing when the request queued nothing', function (): void {
        expect(partsOf(new Container, new AsyncRecorder)['Async dispatched'])->toBeNull();
    });
});

it('reports how WordPress is configured, from its environment and database', function (): void {
    Functions\when('wp_get_environment_type')->justReturn('staging');
    $GLOBALS['wpdb'] = new class
    {
        public function db_server_info(): string
        {
            return '8.0.36-MySQL';
        }
    };
    $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.27';

    $wordpress = partsOf(new Container)['WordPress'];
    unset($GLOBALS['wpdb'], $_SERVER['SERVER_SOFTWARE']);

    expect($wordpress['environment'])->toBe('staging')
        ->and($wordpress['database server'])->toBe('8.0.36-MySQL')
        ->and($wordpress['web server'])->toBe('nginx/1.27')
        ->and($wordpress['constants'])->toHaveKeys(['WP_DEBUG', 'SAVEQUERIES'])
        ->and($wordpress['drop-ins'])->toBe('none');
});

it('shows the template that answered, with every fact of its resolution', function (): void {
    $answering = new AnsweringTemplate;
    $answering->record(new TemplateResolution(
        template: '/elsewhere/single.blade.php',
        condition: 'is_single',
        view: 'single',
        usedIndexFallback: true,
        outcome: TemplateOutcome::View,
    ));
    $container = new Container;
    $container->instance(AnsweringTemplate::class, $answering);

    expect(partsOf($container)['Template'])->toBe([
        'file' => '/elsewhere/single.blade.php',
        'view' => 'single',
        'condition' => 'is_single',
        'index fallback' => true,
        'outcome' => 'view',
    ]);
});

it('lists every scan of discovery with its path, source, structures and time, and adds up the time', function (): void {
    $cache = new class
    {
        public function isCacheEnabled(): bool
        {
            return false;
        }

        public function scans(): array
        {
            return [
                ['path' => '/app/Http', 'source' => 'scan', 'structures' => 3, 'milliseconds' => 1.25],
                ['path' => '/app/Models', 'source' => 'cache', 'structures' => 12, 'milliseconds' => 2.5],
            ];
        }
    };
    $engine = new class($cache)
    {
        public function __construct(private object $cache) {}

        public function getCacheManager(): object
        {
            return $this->cache;
        }
    };
    $container = new Container;
    $container->instance(DiscoveryManager::class, new class($engine)
    {
        public function __construct(private object $engine) {}

        public function getEngine(): object
        {
            return $this->engine;
        }
    });

    expect(partsOf($container)['Discovery'])->toBe([
        'persistent cache' => 'off (debug mode)',
        'time' => '3.8 ms',
        'locations' => [
            '/app/Http · scan · 3 structures · 1.2 ms',
            '/app/Models · cache · 12 structures · 2.5 ms',
        ],
    ]);
});

it('says the discovery cache is on when it is, and takes a cache that cannot tell its scans as having none', function (): void {
    $cache = new class
    {
        public function isCacheEnabled(): bool
        {
            return true;
        }
    };
    $engine = new class($cache)
    {
        public function __construct(private object $cache) {}

        public function getCacheManager(): object
        {
            return $this->cache;
        }
    };
    $container = new Container;
    $container->instance(DiscoveryManager::class, new class($engine)
    {
        public function __construct(private object $engine) {}

        public function getEngine(): object
        {
            return $this->engine;
        }
    });

    expect(partsOf($container)['Discovery'])->toBe(['persistent cache' => 'on', 'time' => '0.0 ms', 'locations' => []]);
});

it('writes the name and version of the theme as text, whatever it reads', function (): void {
    Functions\when('wp_get_theme')->justReturn(new class
    {
        public function get(string $header): mixed
        {
            return ['Name' => 12, 'Version' => 3][$header];
        }

        public function get_stylesheet(): string
        {
            return 's';
        }

        public function get_template(): string
        {
            return 't';
        }
    });

    expect(partsOf(new Container)['Theme'])->toBe(['name' => '12', 'version' => '3', 'stylesheet' => 's', 'template' => 't']);
});
