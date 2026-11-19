<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Fruitcake\LaravelDebugbar\LaravelDebugbar;
use Fruitcake\LaravelDebugbar\Middleware\DebugbarEnabled;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Route;
use Pollora\BlockBinding\Domain\Events\BindingResolved;
use Pollora\Debugbar\CollectorRegistrar;
use Pollora\Debugbar\DebugbarServiceProvider;
use Pollora\Debugbar\Recording\BlockRecorder;
use Pollora\Debugbar\Recording\HookTimer;
use Pollora\WordPress\Events\WordPressBooting;

/**
 * The provider decides once, in register(), whether it runs: these tests make
 * the application believe it serves a web request in debug mode, then register
 * the provider again as a real request would have.
 */
function registerForTheWeb(Application $app, array $config = []): void
{
    (new ReflectionProperty($app, 'isRunningInConsole'))->setValue($app, false);
    config(['app.debug' => true, 'debugbar.enabled' => null, ...$config]);
    $app['env'] = 'local';

    (new DebugbarServiceProvider($app))->register();
}

/**
 * Run the callbacks the provider added with $app->booting(), which Laravel
 * would have run while booting.
 */
function runBootingCallbacks(Application $app, int $skip): void
{
    $callbacks = (new ReflectionProperty($app, 'bootingCallbacks'))->getValue($app);

    foreach (array_slice($callbacks, $skip) as $callback) {
        $callback($app);
    }
}

function doctorRoute(Application $app): ?Route
{
    $app['router']->getRoutes()->refreshNameLookups();

    return $app['router']->getRoutes()->getByName('debugbar.pollora.doctor');
}

function bootingCallbackCount(Application $app): int
{
    return count((new ReflectionProperty($app, 'bootingCallbacks'))->getValue($app));
}

it('switches the bar off inside an iframe, where it would cover the Site Editor canvas', function (): void {
    $skip = bootingCallbackCount($this->app);
    registerForTheWeb($this->app);
    $this->app['request']->headers->set('Sec-Fetch-Dest', 'iframe');

    runBootingCallbacks($this->app, $skip);

    expect(config('debugbar.inject'))->toBeFalse();
});

it('keeps the bar in an iframe when the config asks for it', function (): void {
    config(['debugbar.inject' => true]);
    $skip = bootingCallbackCount($this->app);
    registerForTheWeb($this->app, ['debugbar-pollora.iframes' => true]);
    $this->app['request']->headers->set('Sec-Fetch-Dest', 'iframe');

    runBootingCallbacks($this->app, $skip);

    expect(config('debugbar.inject'))->toBeTrue();
});

it('keeps the bar on a page of its own', function (): void {
    config(['debugbar.inject' => true]);
    $skip = bootingCallbackCount($this->app);
    registerForTheWeb($this->app);

    runBootingCallbacks($this->app, $skip);

    expect(config('debugbar.inject'))->toBeTrue();
});

it('records the block bindings that resolve, when the blocks tab is on', function (): void {
    registerForTheWeb($this->app);

    event(new BindingResolved('acme/event', 'seats', 'content', 7, 1.0, false, true));

    expect($this->app->make(BlockRecorder::class)->bindings())->toHaveCount(1);
});

it('records no block bindings when the blocks tab is off', function (): void {
    registerForTheWeb($this->app, ['debugbar-pollora.collectors.wp_blocks' => false]);

    event(new BindingResolved('acme/event', 'seats', 'content', 7, 1.0, false, true));

    expect($this->app->make(BlockRecorder::class)->bindings())->toBe([]);
});

it('times hook callbacks only when the config asks for it', function (): void {
    registerForTheWeb($this->app, ['debugbar-pollora.options.wp_hooks.timings' => true]);

    event(new WordPressBooting(lightweight: false));

    expect($this->app->make(HookTimer::class)->isInstalled())->toBeTrue();
});

it('leaves hook timings off by default, since they wrap every callback', function (): void {
    registerForTheWeb($this->app);

    event(new WordPressBooting(lightweight: false));

    expect($this->app->make(HookTimer::class)->isInstalled())->toBeFalse();
});

it('bridges Query Monitor and its own messages once WordPress announces itself', function (): void {
    $added = [];
    foreach (['pollora/debugbar/message', 'qm/debug'] as $action) {
        Actions\expectAdded($action)->zeroOrMoreTimes()->whenHappen(function () use (&$added, $action): void {
            $added[] = $action;
        });
    }
    registerForTheWeb($this->app);

    event(new WordPressBooting(lightweight: false));

    expect($added)->toBe(['pollora/debugbar/message', 'qm/debug']);
});

it('leaves the bridges out when the config turns them off', function (): void {
    $added = [];
    Actions\expectAdded('pollora/debugbar/message')->zeroOrMoreTimes()->whenHappen(function () use (&$added): void {
        $added[] = 'message';
    });
    registerForTheWeb($this->app, ['debugbar-pollora.collectors.bridges' => false]);

    event(new WordPressBooting(lightweight: false));

    expect($added)->toBe([]);
});

it('collects a request WordPress exits from with the tabs added, once the routes know their names', function (): void {
    $shutdown = null;
    Actions\expectAdded('shutdown')->whenHappen(function (callable $callback) use (&$shutdown): void {
        $shutdown = $callback;
    });
    Functions\when('wp_doing_ajax')->justReturn(true);
    // Functions another test mocked stay defined for the whole process
    Functions\when('do_action')->justReturn(null);
    Functions\when('get_queried_object')->justReturn(null);
    Functions\when('wp_script_modules')->justReturn(new stdClass);
    Functions\when('home_url')->justReturn('https://site.test');
    Functions\when('determine_locale')->justReturn('en_US');
    Functions\when('get_user_locale')->justReturn('en_US');
    Functions\when('wp_using_ext_object_cache')->justReturn(false);

    registerForTheWeb($this->app);
    $debugbar = $this->collectingDebugbar();
    event(new WordPressBooting(lightweight: false));

    $shutdown();

    expect($this->app->make(CollectorRegistrar::class)->isRegistered())->toBeTrue()
        ->and($debugbar->hasCollector('wp_request'))->toBeTrue()
        ->and($debugbar->getStorage()->saved)->toHaveKey($debugbar->getCurrentRequestId());
});

describe('boot', function (): void {
    it('adds the doctor route, behind the same middleware as Debugbar\'s own', function (): void {
        registerForTheWeb($this->app);

        (new DebugbarServiceProvider($this->app))->boot();

        $route = doctorRoute($this->app);

        expect($route)->not->toBeNull()
            ->and($route->uri())->toBe('_debugbar/pollora/doctor')
            ->and($route->middleware())->toContain(DebugbarEnabled::class);
    });

    it('follows the route prefix Debugbar was configured with', function (): void {
        registerForTheWeb($this->app, ['debugbar.route_prefix' => '/tools/bar/']);

        (new DebugbarServiceProvider($this->app))->boot();

        expect(doctorRoute($this->app)->uri())->toBe('tools/bar/pollora/doctor');
    });

    it('adds no doctor route when the doctor tab is off', function (): void {
        registerForTheWeb($this->app, ['debugbar-pollora.collectors.doctor' => false]);

        (new DebugbarServiceProvider($this->app))->boot();

        expect(doctorRoute($this->app))->toBeNull();
    });

    it('adds the tabs once the application has booted and the bar collects', function (): void {
        registerForTheWeb($this->app);
        $debugbar = $this->collectingDebugbar();

        (new DebugbarServiceProvider($this->app))->boot();

        expect($debugbar->hasCollector('wp_queries'))->toBeTrue();
    });

    it('adds nothing, not even the route, outside the web', function (): void {
        (new DebugbarServiceProvider($this->app))->boot();

        expect(doctorRoute($this->app))->toBeNull();
    });
});

it('adds no tabs at boot when the bar is not bound', function (): void {
    registerForTheWeb($this->app);
    unset($this->app[LaravelDebugbar::class]);

    (new DebugbarServiceProvider($this->app))->boot();

    expect($this->app->make(CollectorRegistrar::class)->isRegistered())->toBeFalse();
});
