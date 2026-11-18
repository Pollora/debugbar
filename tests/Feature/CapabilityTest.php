<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Illuminate\Foundation\Application;
use Pollora\Debugbar\Activation;
use Pollora\Debugbar\DebugbarServiceProvider;
use Pollora\Debugbar\Http\DoctorController;
use Pollora\Debugbar\Http\RequireCapability;
use Pollora\WordPress\Events\WordPressBooting;

if (! class_exists(WP_REST_Response::class)) {
    class WP_REST_Response
    {
        /** @var array<string, string> */
        public array $headers = [];

        public function header(string $key, string $value): void
        {
            $this->headers[$key] = $value;
        }
    }
}

if (! function_exists('capabilityServeTheWeb')) {
    /**
     * Makes the application believe it serves a debug web request, then
     * registers the provider as a real request would have.
     *
     * @param  array<string, mixed>  $config
     */
    function capabilityServeTheWeb(Application $app, array $config = []): void
    {
        (new ReflectionProperty($app, 'isRunningInConsole'))->setValue($app, false);
        config(['app.debug' => true, 'debugbar.enabled' => null, ...$config]);
        $app['env'] = 'local';

        (new DebugbarServiceProvider($app))->register();
    }
}

if (! function_exists('capabilityUserHolds')) {
    /**
     * Stands in for the current WordPress user, who holds exactly these capabilities.
     *
     * @param  list<string>  $capabilities
     */
    function capabilityUserHolds(array $capabilities): void
    {
        Functions\when('current_user_can')->alias(static fn (string $capability): bool => in_array($capability, $capabilities, true));
    }
}

/**
 * Who is signed in, as the capabilities WordPress would grant them.
 */
dataset('visitors', [
    'an administrator' => [['manage_options', 'edit_posts', 'read'], true],
    'an editor' => [['edit_posts', 'read'], false],
    'an anonymous visitor' => [[], false],
]);

describe('which users may see the bar', function (): void {
    it('defaults to manage_options', function (): void {
        expect(Activation::capability($this->app))->toBe('manage_options');
    });

    it('lets only a user with the capability see the bar', function (array $capabilities, bool $allowed): void {
        capabilityUserHolds($capabilities);

        expect(Activation::mayView($this->app))->toBe($allowed);
    })->with('visitors');

    it('asks for the configured capability instead', function (): void {
        config(['debugbar-pollora.capability' => 'edit_posts']);
        capabilityUserHolds(['edit_posts']);

        expect(Activation::capability($this->app))->toBe('edit_posts')
            ->and(Activation::mayView($this->app))->toBeTrue();
    });

    it('refuses an administrator once another capability is required', function (): void {
        config(['debugbar-pollora.capability' => 'debug_acme']);
        capabilityUserHolds(['manage_options']);

        expect(Activation::mayView($this->app))->toBeFalse();
    });

    it('falls back to manage_options when the setting is blank or not a string, never to open access', function (mixed $setting): void {
        config(['debugbar-pollora.capability' => $setting]);

        expect(Activation::capability($this->app))->toBe('manage_options');
    })->with([[''], ['   '], [null], [false], [['edit_posts']]]);

    it('stays inactive in production, whoever is signed in', function (): void {
        capabilityUserHolds(['manage_options']);
        (new ReflectionProperty($this->app, 'isRunningInConsole'))->setValue($this->app, false);
        config(['app.debug' => true, 'debugbar.enabled' => null]);
        $this->app['env'] = 'production';

        expect(Activation::shouldRun($this->app))->toBeFalse();
    });
});

describe('the bar of a page', function (): void {
    it('is switched off at init for a user without the capability and kept for an administrator', function (array $capabilities, bool $allowed): void {
        // The request recorder listens to `init` too: run every callback
        $init = [];
        Actions\expectAdded('init')->zeroOrMoreTimes()->whenHappen(function (callable $callback) use (&$init): void {
            $init[] = $callback;
        });
        capabilityServeTheWeb($this->app);
        $debugbar = $this->collectingDebugbar();
        event(new WordPressBooting(lightweight: false));
        capabilityUserHolds($capabilities);

        array_map(static fn (callable $callback): mixed => $callback(), $init);

        expect($debugbar->isEnabled())->toBe($allowed);
    })->with('visitors');

    it('adds no tab for a user without the capability', function (): void {
        capabilityUserHolds(['edit_posts']);
        capabilityServeTheWeb($this->app);
        $debugbar = $this->collectingDebugbar();

        (new DebugbarServiceProvider($this->app))->boot();

        expect($debugbar->hasCollector('wp_queries'))->toBeFalse();
    });

    it('adds the tabs for an administrator', function (): void {
        capabilityUserHolds(['manage_options']);
        capabilityServeTheWeb($this->app);
        $debugbar = $this->collectingDebugbar();

        (new DebugbarServiceProvider($this->app))->boot();

        expect($debugbar->hasCollector('wp_queries'))->toBeTrue();
    });

    it('tags a REST response with its stored request for an administrator only', function (array $capabilities, bool $allowed): void {
        $tag = null;
        Filters\expectAdded('rest_post_dispatch')->whenHappen(function (callable $callback) use (&$tag): void {
            $tag = $callback;
        });
        capabilityServeTheWeb($this->app);
        $this->collectingDebugbar();
        event(new WordPressBooting(lightweight: false));
        capabilityUserHolds($capabilities);

        $response = $tag(new WP_REST_Response);

        expect($response->headers === [])->toBe(! $allowed);
    })->with('visitors');
});

describe('the endpoints', function (): void {
    beforeEach(function (): void {
        $this->app['router']->get('_debugbar/probe', fn (): string => 'collected data')->middleware(RequireCapability::class);
    });

    it('answers 403 to a user without the capability and serves an administrator', function (array $capabilities, bool $allowed): void {
        capabilityUserHolds($capabilities);

        $response = $this->get('_debugbar/probe');

        $allowed ? $response->assertOk()->assertSee('collected data') : $response->assertForbidden()->assertDontSee('collected data');
    })->with('visitors');

    it('is added to the routes of Debugbar itself, once, when the bar runs', function (): void {
        capabilityServeTheWeb($this->app);
        (new DebugbarServiceProvider($this->app))->register();

        expect(array_count_values(config('debugbar.route_middleware')))->toHaveKey(RequireCapability::class, 1);
    });

    it('is not added when the bar does not run', function (): void {
        expect(config('debugbar.route_middleware', []))->not->toContain(RequireCapability::class);
    });

    it('keeps the doctor from running its checks for a user without the capability', function (array $capabilities, bool $allowed): void {
        config(['debugbar.force_allow_enable' => true, 'debugbar.enabled' => true, 'debugbar.storage.open' => true]);
        $this->app['router']->get('_debugbar/pollora/doctor', DoctorController::class);
        $this->collectingDebugbar();
        capabilityUserHolds($capabilities);

        $response = $this->getJson('_debugbar/pollora/doctor');

        // Allowed users get past the capability: the framework's doctor is not bound here
        $allowed ? expect($response->status())->not->toBe(403) : $response->assertForbidden()->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'manage_options'));
    })->with('visitors');
});
