<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use DebugBar\DataFormatter\DataFormatter;
use Pollora\BlockBinding\Domain\Events\BindingResolved;
use Pollora\Debugbar\Collectors\WpBlocksCollector;
use Pollora\Debugbar\Collectors\WpCapabilitiesCollector;
use Pollora\Debugbar\Collectors\WpHttpCollector;
use Pollora\Debugbar\Recording\AsyncRecorder;
use Pollora\Debugbar\Recording\BlockRecorder;
use Pollora\Debugbar\Recording\CacheRecorder;
use Pollora\Debugbar\Recording\CapabilityRecorder;
use Pollora\Debugbar\Recording\HttpRecorder;
use Pollora\Debugbar\Recording\LanguageRecorder;
use Pollora\Debugbar\Support\Components;

/**
 * Brain Monkey records registrations without running them: the callbacks are
 * captured by hook name and called by hand, as WordPress would.
 *
 * @param  list<string>  $hooks
 */
function captured(array $hooks): ArrayObject
{
    $callbacks = new ArrayObject;

    foreach ($hooks as $hook) {
        Filters\expectAdded($hook)->zeroOrMoreTimes()->whenHappen(function (callable $callback) use ($callbacks, $hook): void {
            $callbacks[$hook] = $callback;
        });
        Actions\expectAdded($hook)->zeroOrMoreTimes()->whenHappen(function (callable $callback) use ($callbacks, $hook): void {
            $callbacks[$hook] = $callback;
        });
    }

    return $callbacks;
}

function noComponents(): Components
{
    return new Components([]);
}

describe('HTTP calls', function (): void {
    it('pairs each response with its request, even when a plugin answers in its place', function (): void {
        $hooks = captured(['http_request_args', 'pre_http_request', 'http_api_debug']);
        $recorder = new HttpRecorder;
        $recorder->install();

        $first = $hooks['http_request_args'](['method' => 'post'], 'https://api.example.test/orders');
        $second = $hooks['http_request_args'](['method' => 'GET'], 'https://api.example.test/stock');
        $hooks['pre_http_request'](['response' => ['code' => 200]], $second);
        $hooks['http_api_debug'](['response' => ['code' => 201]], 'response', 'WpOrg\\Requests\\Requests', $first);

        $rows = (new WpHttpCollector($recorder, noComponents()))->setDataFormatter(new DataFormatter)->collect()['data']['data'];

        expect(array_keys($rows))->toBe(['1. POST https://api.example.test/orders', '2. GET https://api.example.test/stock'])
            ->and($rows['1. POST https://api.example.test/orders']['result'])->toBe('201')
            ->and($rows['1. POST https://api.example.test/orders']['transport'])->toBe('WpOrg\\Requests\\Requests')
            ->and($rows['2. GET https://api.example.test/stock']['result'])->toBe('200 (answered by pre_http_request)');
    });

    it('leaves a pre_http_request that lets the call through untouched', function (): void {
        $hooks = captured(['http_request_args', 'pre_http_request', 'http_api_debug']);
        $recorder = new HttpRecorder;
        $recorder->install();

        $args = $hooks['http_request_args']([], 'https://example.test');

        expect($hooks['pre_http_request'](false, $args))->toBeFalse()
            ->and($recorder->calls()[0]['end'])->toBeNull();
    });
});

describe('capability checks', function (): void {
    it('counts identical checks once, and tells granted from refused', function (): void {
        $hooks = captured(['user_has_cap']);
        $recorder = new CapabilityRecorder;
        $recorder->install();

        $allcaps = ['edit_posts' => true];
        foreach ([1, 2, 3] as $ignored) {
            $hooks['user_has_cap']($allcaps, ['edit_posts'], ['edit_posts', 5]);
        }
        $returned = $hooks['user_has_cap']($allcaps, ['edit_others_posts'], ['edit_post', 5, 42]);

        $rows = (new WpCapabilitiesCollector($recorder, noComponents()))->collect()['data']['data'];

        expect($returned)->toBe($allcaps)
            ->and($rows['edit_posts'])->toMatchArray(['result' => 'granted', 'user' => '#5', 'count' => 3])
            ->and($rows['edit_post(42)'])->toMatchArray(['result' => 'refused', 'count' => 1]);
    });

    it('refuses a check that maps to no capability, as WordPress does', function (): void {
        $hooks = captured(['user_has_cap']);
        $recorder = new CapabilityRecorder;
        $recorder->install();

        $hooks['user_has_cap'](['read' => true], [], ['do_not_allow', 0]);

        expect($recorder->checks()[0]['granted'])->toBeFalse();
    });
});

describe('blocks', function (): void {
    it('times each block type, counting inner blocks inside their parent', function (): void {
        $hooks = captured(['pre_render_block', 'render_block']);
        $recorder = new BlockRecorder;
        $recorder->install();

        $hooks['pre_render_block'](null, ['blockName' => 'core/group']);
        $hooks['pre_render_block'](null, ['blockName' => 'core/paragraph']);
        $hooks['render_block']('<p>a</p>');
        $hooks['pre_render_block'](null, ['blockName' => 'core/paragraph']);
        $hooks['render_block']('<p>b</p>');
        $hooks['render_block']('<div></div>');

        expect($recorder->blocks()['core/paragraph'])->toMatchArray(['count' => 2, 'maxDepth' => 1])
            ->and($recorder->blocks()['core/group'])->toMatchArray(['count' => 1, 'maxDepth' => 0]);
    });

    it('opens nothing for a block a plugin answers in pre_render_block', function (): void {
        $hooks = captured(['pre_render_block', 'render_block']);
        $recorder = new BlockRecorder;
        $recorder->install();

        $hooks['pre_render_block']('<p>cached</p>', ['blockName' => 'acme/cached']);

        expect($recorder->blocks())->toBe([]);
    });

    it('groups bindings by source, field and attribute', function (): void {
        $recorder = new BlockRecorder;
        $binding = fn (bool $cached, bool $hasValue): BindingResolved => new BindingResolved('acme/event', 'seats', 'content', 7, $cached ? 0.0 : 1.5, $cached, $hasValue);
        $recorder->recordBinding($binding(false, true));
        $recorder->recordBinding($binding(true, true));
        $recorder->recordBinding($binding(false, false));

        $rows = (new WpBlocksCollector($recorder))->setDataFormatter(new DataFormatter)->collect()['data']['data'];

        expect($rows['binding acme/event.seats → content'])->toMatchArray([
            'count' => 3,
            'kind' => 'binding',
            'detail' => 'post 7, 1 from cache, 1 without value',
        ]);
    });
});

describe('transients, languages and async actions', function (): void {
    it('keeps the transients set, with their size and expiration', function (): void {
        $hooks = captured(['set_transient', 'set_site_transient']);
        $recorder = new CacheRecorder;
        $recorder->install();

        $hooks['set_transient']('acme_rates', ['eur' => 1.0], 3600);
        $hooks['set_site_transient']('acme_feed', 'x', 0);

        expect($recorder->transients())->toHaveCount(2)
            ->and($recorder->transients()[0])->toMatchArray(['name' => 'acme_rates', 'network' => false, 'expiration' => 3600, 'size' => strlen(serialize(['eur' => 1.0]))])
            ->and($recorder->transients()[1]['network'])->toBeTrue();
    });

    it('keeps the translation files asked for, and whether they exist', function (): void {
        $hooks = captured(['load_translation_file', 'load_script_translation_file']);
        $recorder = new LanguageRecorder;
        $recorder->install();

        $returned = $hooks['load_translation_file'](__FILE__, 'acme');
        $hooks['load_translation_file']('/nowhere/acme-fr_FR.mo', 'acme');

        expect($returned)->toBe(__FILE__)
            ->and(array_column($recorder->files(), 'found'))->toBe([true, false]);
    });

    it('keeps the async actions the request queued', function (): void {
        $hooks = captured(['pollora/async/dispatched']);
        $recorder = new AsyncRecorder;
        $recorder->install();

        $hooks['pollora/async/dispatched']((object) ['hook' => 'save_post', 'handler' => 'App\\Sync@handle', 'driver' => 'queue'], 30);

        expect($recorder->dispatched())->toBe([['hook' => 'save_post', 'handler' => 'App\\Sync@handle', 'driver' => 'queue', 'delay' => 30]]);
    });
});
