<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Pollora\Debugbar\Collectors\WpHttpCollector;
use Pollora\Debugbar\Recording\AsyncRecorder;
use Pollora\Debugbar\Recording\BlockRecorder;
use Pollora\Debugbar\Recording\CacheRecorder;
use Pollora\Debugbar\Recording\CapabilityRecorder;
use Pollora\Debugbar\Recording\HttpRecorder;
use Pollora\Debugbar\Recording\LanguageRecorder;
use Pollora\Debugbar\Recording\QueryTracer;
use Pollora\Debugbar\Recording\RequestRecorder;
use Pollora\Debugbar\Recording\SiteRecorder;
use Pollora\Debugbar\Support\Components;

require_once __DIR__.'/../../Fixtures/wordpress.php';

/**
 * Each recorder listens once, however many times it is installed, and starts
 * again from nothing on reset.
 */
dataset('recorders that listen through hooks', [
    'async actions' => [fn () => new AsyncRecorder, ['pollora/async/dispatched']],
    'blocks' => [fn () => new BlockRecorder, ['pre_render_block', 'render_block']],
    'transients' => [fn () => new CacheRecorder, ['set_transient', 'set_site_transient']],
    'capabilities' => [fn () => new CapabilityRecorder, ['user_has_cap']],
    'HTTP calls' => [fn () => new HttpRecorder, ['http_request_args', 'pre_http_request', 'http_api_debug']],
    'translations' => [fn () => new LanguageRecorder, ['load_translation_file', 'load_script_translation_file']],
    'site switches' => [fn () => new SiteRecorder, ['switch_blog']],
]);

it('registers its listeners once, however many times it is installed', function (Closure $make, array $hooks): void {
    $added = 0;

    foreach ($hooks as $hook) {
        $count = function () use (&$added): void {
            $added++;
        };
        Actions\expectAdded($hook)->zeroOrMoreTimes()->whenHappen($count);
        Filters\expectAdded($hook)->zeroOrMoreTimes()->whenHappen($count);
    }

    $recorder = $make();
    $recorder->install();
    $afterFirst = $added;
    $recorder->install();

    expect($afterFirst)->toBeGreaterThanOrEqual(count($hooks))
        ->and($added)->toBe($afterFirst);
})->with('recorders that listen through hooks');

it('registers its listeners once when the query tracer is installed twice', function (): void {
    $added = 0;
    Filters\expectAdded('log_query_custom_data')->zeroOrMoreTimes()->whenHappen(function () use (&$added): void {
        $added++;
    });
    $tracer = new QueryTracer;

    $tracer->install();
    $tracer->install();

    expect($added)->toBe(1);
});

it('forgets what it recorded on reset', function (): void {
    $async = new AsyncRecorder;
    (new ReflectionProperty($async, 'dispatched'))->setValue($async, [['hook' => 'h', 'handler' => 'x', 'driver' => '', 'delay' => 0]]);
    $cache = new CacheRecorder;
    (new ReflectionMethod($cache, 'record'))->invoke($cache, 'name', 'value', 0, false);
    $language = new LanguageRecorder;
    (new ReflectionMethod($language, 'record'))->invoke($language, 'acme', '', 'php / mo');
    $capabilities = new CapabilityRecorder;
    (new ReflectionMethod($capabilities, 'record'))->invoke($capabilities, ['read' => true], ['read'], ['read', 1]);
    $blocks = new BlockRecorder;
    (new ReflectionProperty($blocks, 'open'))->setValue($blocks, [['name' => 'core/group', 'start' => 0.0]]);
    (new ReflectionProperty($blocks, 'blocks'))->setValue($blocks, ['core/group' => ['count' => 1, 'milliseconds' => 1.0, 'maxDepth' => 0]]);
    $http = new HttpRecorder;
    (new ReflectionMethod($http, 'starts'))->invoke($http, [], 'https://example.test');
    $sites = new SiteRecorder;
    (new ReflectionProperty($sites, 'switches'))->setValue($sites, [['from' => 1, 'to' => 2, 'context' => 'switch', 'frames' => []]]);

    foreach ([$async, $cache, $language, $capabilities, $blocks, $http, $sites] as $recorder) {
        $recorder->reset();
    }

    expect($async->dispatched())->toBe([])
        ->and($cache->transients())->toBe([])
        ->and($language->files())->toBe([])
        ->and($capabilities->checks())->toBe([])
        ->and($blocks->blocks())->toBe([])
        ->and($http->calls())->toBe([])
        ->and($sites->switches())->toBe([]);
});

it('clears the phases, hierarchies and hook counts of the request on reset', function (): void {
    $recorder = new RequestRecorder;
    (new ReflectionProperty($recorder, 'hookCalls'))->setValue($recorder, ['init' => 3]);
    (new ReflectionProperty($recorder, 'bootedAt'))->setValue($recorder, 1.0);
    (new ReflectionProperty($recorder, 'templateInclude'))->setValue($recorder, '/theme/index.php');

    $recorder->reset();

    expect($recorder->hookCalls())->toBeNull()
        ->and($recorder->phases())->toBe([])
        ->and($recorder->template())->toBeNull()
        ->and($recorder->hierarchies())->toBe([])
        ->and($recorder->bootedAt())->toBeNull();
});

describe('capability backtraces', function (): void {
    it('keeps the files that led to a check when backtraces are on', function (): void {
        $recorder = new CapabilityRecorder(backtrace: true);

        (new ReflectionMethod($recorder, 'record'))->invoke($recorder, ['edit_posts' => true], ['edit_posts'], ['edit_posts', 3]);

        expect($recorder->checks()[0]['frames'])->not->toBeEmpty()
            ->and($recorder->checks()[0]['frames'][0])->toHaveKey('file');
    });

    it('keeps none by default, which is what keeps the cost low', function (): void {
        $hooks = new ArrayObject;
        Filters\expectAdded('user_has_cap')->zeroOrMoreTimes()->whenHappen(function (callable $callback) use ($hooks): void {
            $hooks['cb'] = $callback;
        });
        $recorder = new CapabilityRecorder;
        $recorder->install();

        $hooks['cb'](['read' => true], ['read'], ['read', 1]);

        expect($recorder->checks()[0]['frames'])->toBe([]);
    });

    it('ignores a check whose arguments are not what WordPress passes', function (): void {
        $hooks = new ArrayObject;
        Filters\expectAdded('user_has_cap')->zeroOrMoreTimes()->whenHappen(function (callable $callback) use ($hooks): void {
            $hooks['cb'] = $callback;
        });
        $recorder = new CapabilityRecorder;
        $recorder->install();

        $returned = $hooks['cb']('nope', ['read'], ['read', 1]);

        expect($returned)->toBe('nope')
            ->and($recorder->checks())->toBe([]);
    });

    it('describes non-scalar arguments of a check by their type', function (): void {
        $recorder = new CapabilityRecorder;

        (new ReflectionMethod($recorder, 'record'))->invoke($recorder, ['edit_post' => true], ['edit_post'], ['edit_post', 1, new stdClass, 42]);

        expect($recorder->checks()[0]['arguments'])->toBe('stdClass, 42');
    });
});

describe('blocks', function (): void {
    it('ignores a render_block that closes nothing, as when a plugin short-circuited the render', function (): void {
        $hooks = new ArrayObject;
        Filters\expectAdded('render_block')->zeroOrMoreTimes()->whenHappen(function (callable $callback) use ($hooks): void {
            $hooks['render_block'] = $callback;
        });
        $recorder = new BlockRecorder;
        $recorder->install();

        $content = $hooks['render_block']('<p>x</p>');

        expect($content)->toBe('<p>x</p>')
            ->and($recorder->blocks())->toBe([]);
    });

    it('names a block with no name "freeform"', function (): void {
        $hooks = new ArrayObject;
        foreach (['pre_render_block', 'render_block'] as $hook) {
            Filters\expectAdded($hook)->zeroOrMoreTimes()->whenHappen(function (callable $callback) use ($hooks, $hook): void {
                $hooks[$hook] = $callback;
            });
        }
        $recorder = new BlockRecorder;
        $recorder->install();

        $hooks['pre_render_block'](null, ['innerHTML' => 'text']);
        $hooks['render_block']('text');

        expect(array_keys($recorder->blocks()))->toBe(['freeform']);
    });
});

describe('HTTP calls', function (): void {
    function httpHooks(HttpRecorder $recorder): ArrayObject
    {
        $hooks = new ArrayObject;
        foreach (['http_request_args', 'pre_http_request', 'http_api_debug'] as $hook) {
            Filters\expectAdded($hook)->zeroOrMoreTimes()->whenHappen(function (callable $callback) use ($hooks, $hook): void {
                $hooks[$hook] = $callback;
            });
            Actions\expectAdded($hook)->zeroOrMoreTimes()->whenHappen(function (callable $callback) use ($hooks, $hook): void {
                $hooks[$hook] = $callback;
            });
        }
        $recorder->install();

        return $hooks;
    }

    it('records the error of a call that failed', function (): void {
        $recorder = new HttpRecorder;
        $hooks = httpHooks($recorder);

        $args = $hooks['http_request_args']([], 'https://api.example.test');
        $hooks['http_api_debug'](new WP_Error('http_request_failed', 'cURL error 28: timeout'), 'response', 'curl', $args);

        $call = $recorder->calls()[0];

        expect($call['error'])->toBe('cURL error 28: timeout')
            ->and($call['status'])->toBeNull()
            ->and($call['end'])->not->toBeNull();
    });

    it('leaves request arguments alone when they are not an array', function (): void {
        $recorder = new HttpRecorder;
        $hooks = httpHooks($recorder);

        expect($hooks['http_request_args']('odd', 'https://x.test'))->toBe('odd')
            ->and($recorder->calls())->toBe([]);
    });

    it('keeps no status for a response that carries none', function (): void {
        $recorder = new HttpRecorder;
        $hooks = httpHooks($recorder);

        $args = $hooks['http_request_args']([], 'https://x.test');
        $hooks['http_api_debug']([], 'response', 'curl', $args);
        $hooks['http_api_debug']('not a response', 'response', 'curl', $args);

        expect($recorder->calls()[0]['status'])->toBeNull();
    });

    it('does not mix up a response whose request it never saw', function (): void {
        $recorder = new HttpRecorder;
        $hooks = httpHooks($recorder);

        $hooks['http_api_debug'](['response' => ['code' => 200]], 'response', 'curl', ['_pollora_debugbar_call' => '99']);
        $hooks['http_api_debug'](['response' => ['code' => 200]], 'response', 'curl', 'no args');

        expect($recorder->calls())->toBe([]);
    });

    it('shows a call with no response, an error, and one answered by a plugin', function (): void {
        $recorder = new HttpRecorder;
        $hooks = httpHooks($recorder);
        $hooks['http_request_args']([], 'https://pending.test');
        $failed = $hooks['http_request_args']([], 'https://failed.test');
        $hooks['http_api_debug'](new WP_Error('x', 'refused'), 'response', 'curl', $failed);
        $cached = $hooks['http_request_args']([], 'https://cached.test');
        $hooks['pre_http_request'](['no' => 'response key'], $cached);

        $rows = (new WpHttpCollector($recorder, new Components([])))->collect()['data']['data'];

        expect(array_column($rows, 'result'))->toBe(['no response recorded', 'error: refused', 'answered (answered by pre_http_request)']);
    });
});

describe('translations', function (): void {
    it('keeps the script translation files asked for, by handle', function (): void {
        $hooks = new ArrayObject;
        Filters\expectAdded('load_script_translation_file')->zeroOrMoreTimes()->whenHappen(function (callable $callback) use ($hooks): void {
            $hooks['script'] = $callback;
        });
        $recorder = new LanguageRecorder;
        $recorder->install();

        $returned = $hooks['script'](__FILE__, 'acme-editor', 'acme');
        $ignored = $hooks['script'](false, 'acme-editor', 'acme');

        expect($returned)->toBe(__FILE__)
            ->and($ignored)->toBeFalse()
            ->and($recorder->files())->toBe([['domain' => 'acme', 'file' => __FILE__, 'found' => true, 'kind' => 'script (acme-editor)']]);
    });
});

describe('queries', function (): void {
    beforeEach(function (): void {
        $GLOBALS['wpdb'] = (object) ['queries' => [], 'last_error' => '', 'rows_affected' => 0, 'num_rows' => 0];
    });

    afterEach(function (): void {
        unset($GLOBALS['wpdb']);
    });

    it('completes nothing when WordPress kept no query', function (): void {
        $tracer = new QueryTracer;

        $tracer->completePrevious();
        $GLOBALS['wpdb'] = 'not wpdb';
        $tracer->completePrevious();

        expect($GLOBALS['wpdb'])->toBe('not wpdb');
    });

    it('keeps at most thirty frames of a deep stack, and shows ten', function (): void {
        $hooks = new ArrayObject;
        Filters\expectAdded('log_query_custom_data')->zeroOrMoreTimes()->whenHappen(function (callable $callback) use ($hooks): void {
            $hooks['trace'] = $callback;
        });
        $tracer = new QueryTracer(components: new Components([]));
        $tracer->install();
        $deep = function (int $depth) use (&$deep, $hooks): array {
            return $depth === 0 ? $hooks['trace']([]) : $deep($depth - 1);
        };

        $data = $deep(40);

        expect($data[QueryTracer::KEY]['frames'])->toHaveCount(10);
    });
});
