<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Pollora\Debugbar\Recording\BlockRecorder;
use Pollora\Debugbar\Recording\CacheRecorder;
use Pollora\Debugbar\Recording\HttpRecorder;
use Pollora\Debugbar\Recording\SiteRecorder;

require_once __DIR__.'/../../Fixtures/wordpress.php';

/**
 * @param  list<string>  $hooks
 */
function listenersOf(array $hooks): ArrayObject
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

describe('HTTP recorder details', function (): void {
    function httpRecorder(): array
    {
        $hooks = listenersOf(['http_request_args', 'pre_http_request', 'http_api_debug']);
        $recorder = new HttpRecorder;
        $recorder->install();

        return [$recorder, $hooks];
    }

    it('records each request with its method, url, start and an empty outcome', function (): void {
        [$recorder, $hooks] = httpRecorder();

        $args = $hooks['http_request_args'](['method' => 'post'], 'https://a.test');
        $second = $hooks['http_request_args']([], 'https://b.test');
        $call = $recorder->calls()[0];

        expect($args['_pollora_debugbar_call'])->toBe('0')
            ->and($second['_pollora_debugbar_call'])->toBe('1')
            ->and($call['method'])->toBe('POST')
            ->and($call['url'])->toBe('https://a.test')
            ->and($call['start'])->toBeFloat()->toBeGreaterThan(1_000_000_000)
            ->and($call['end'])->toBeNull()
            ->and($call['status'])->toBeNull()
            ->and($call['error'])->toBeNull()
            ->and($call['transport'])->toBeNull()
            ->and($call['shortCircuited'])->toBeFalse()
            ->and($call['frames'])->not->toBe([])
            ->and(array_column($call['frames'], 'file'))->each->not->toBe('')
            ->and(array_keys($call['frames']))->toBe(range(0, count($call['frames']) - 1))
            ->and($recorder->calls()[1]['method'])->toBe('GET');
    });

    it('reads a method that is not a string as a GET, and leaves arguments that are not an array as they are', function (): void {
        [$recorder, $hooks] = httpRecorder();

        $hooks['http_request_args'](['method' => 7], 'https://a.test');

        expect($recorder->calls()[0]['method'])->toBe('GET')
            ->and($hooks['http_request_args']('junk', 'https://b.test'))->toBe('junk')
            ->and($recorder->calls())->toHaveCount(1);
    });

    it('finishes a call with its transport, status and time, or its error', function (): void {
        [$recorder, $hooks] = httpRecorder();
        $first = $hooks['http_request_args']([], 'https://a.test');
        $second = $hooks['http_request_args']([], 'https://b.test');
        $third = $hooks['http_request_args']([], 'https://c.test');
        $fourth = $hooks['http_request_args']([], 'https://d.test');

        $hooks['http_api_debug'](['response' => ['code' => 200]], 'response', 'curl', $first);
        $hooks['http_api_debug'](new WP_Error('http_request_failed', 'cURL error 28'), 'response', 'streams', $second);
        $hooks['http_api_debug'](['response' => ['code' => '404']], 'response', 42, $third);
        $hooks['http_api_debug'](['response' => ['code' => 1.5]], 'response', 'curl', $fourth);
        $hooks['http_api_debug'](['response' => ['code' => 200]], 'response', 'curl', ['no tag']);
        $hooks['http_api_debug'](['response' => ['code' => 200]], 'response', 'curl', 'junk');

        $calls = $recorder->calls();

        expect($calls[0]['status'])->toBe(200)
            ->and($calls[0]['transport'])->toBe('curl')
            ->and($calls[0]['end'])->toBeFloat()->toBeGreaterThanOrEqual($calls[0]['start'])
            ->and($calls[0]['error'])->toBeNull()
            ->and($calls[1]['error'])->toBe('cURL error 28')
            ->and($calls[1]['status'])->toBeNull()
            ->and($calls[1]['transport'])->toBe('streams')
            ->and($calls[2]['status'])->toBe('404')
            ->and($calls[2]['transport'])->toBeNull()
            ->and($calls[3]['status'])->toBeNull();
    });

    it('marks a call answered in place, with the response it was given, and leaves the others alone', function (): void {
        [$recorder, $hooks] = httpRecorder();
        $first = $hooks['http_request_args']([], 'https://a.test');
        $second = $hooks['http_request_args']([], 'https://b.test');

        $kept = $hooks['pre_http_request'](false, $first);
        $answered = $hooks['pre_http_request'](['response' => ['code' => 204]], $second);
        $hooks['pre_http_request'](['response' => ['code' => 200]], ['no tag']);
        $hooks['pre_http_request'](['response' => ['code' => 200]], 'junk');
        $calls = $recorder->calls();

        expect($kept)->toBeFalse()
            ->and($answered)->toBe(['response' => ['code' => 204]])
            ->and($calls[0]['shortCircuited'])->toBeFalse()
            ->and($calls[0]['end'])->toBeNull()
            ->and($calls[1]['shortCircuited'])->toBeTrue()
            ->and($calls[1]['status'])->toBe(204)
            ->and($calls[1]['transport'])->toBeNull()
            ->and($calls[1]['end'])->toBeFloat();
    });
});

describe('site recorder details', function (): void {
    function siteSwitches(array $switches): array
    {
        $hooks = listenersOf(['switch_blog']);
        $recorder = new SiteRecorder;
        $recorder->install();

        foreach ($switches as $arguments) {
            $hooks['switch_blog'](...$arguments);
        }

        return $recorder->switches();
    }

    it('records each switch with its sites as numbers, its context and the files that led to it', function (): void {
        $switches = siteSwitches([['2', '1', 'switch'], [1, 2, 'restore'], [3, 1, 5], [4, 1]]);

        expect(array_column($switches, 'to'))->toBe([2, 1, 3, 4])
            ->and(array_column($switches, 'from'))->toBe([1, 2, 1, 1])
            ->and(array_column($switches, 'context'))->toBe(['switch', 'restore', 'switch', 'switch'])
            ->and($switches[0]['frames'])->not->toBe([])
            ->and(array_column($switches[0]['frames'], 'file'))->each->not->toBe('')
            ->and(array_keys($switches[0]['frames']))->toBe(range(0, count($switches[0]['frames']) - 1))
            ->and(array_keys($switches[0]['frames'][0]))->toBe(['file']);
    });

    it('keeps at most fifteen frames of a switch', function (): void {
        $hooks = listenersOf(['switch_blog']);
        $recorder = new SiteRecorder;
        $recorder->install();
        $deep = function (int $depth) use (&$deep, $hooks): void {
            $depth === 0 ? $hooks['switch_blog'](2, 1, 'switch') : $deep($depth - 1);
        };

        $deep(30);

        expect($recorder->switches()[0]['frames'])->toHaveCount(15);
    });
});

describe('cache recorder details', function (): void {
    it('records a transient with its name, network, expiration, serialized size and the files that wrote it', function (): void {
        $hooks = listenersOf(['set_transient', 'set_site_transient']);
        $recorder = new CacheRecorder;
        $recorder->install();

        $hooks['set_transient'](42, 'value', '3600');
        $hooks['set_site_transient']('shared', ['a' => 1], 60);
        $local = $recorder->transients()[0];
        $network = $recorder->transients()[1];

        expect($local['name'])->toBe('42')
            ->and($local['network'])->toBeFalse()
            ->and($local['expiration'])->toBe(3600)
            ->and($local['size'])->toBe(strlen(serialize('value')))
            ->and($local['frames'])->not->toBe([])
            ->and(array_column($local['frames'], 'file'))->each->not->toBe('')
            ->and(array_keys($local['frames']))->toBe(range(0, count($local['frames']) - 1))
            ->and($network['name'])->toBe('shared')
            ->and($network['network'])->toBeTrue()
            ->and($network['expiration'])->toBe(60)
            ->and($network['size'])->toBe(strlen(serialize(['a' => 1])));
    });

    it('keeps at most fifteen frames of a transient', function (): void {
        $hooks = listenersOf(['set_transient', 'set_site_transient']);
        $recorder = new CacheRecorder;
        $recorder->install();
        $deep = function (int $depth) use (&$deep, $hooks): void {
            $depth === 0 ? $hooks['set_transient']('t', 1, 0) : $deep($depth - 1);
        };

        $deep(30);

        expect($recorder->transients()[0]['frames'])->toHaveCount(15);
    });
});

describe('block recorder details', function (): void {
    function blockListeners(): array
    {
        $hooks = listenersOf(['pre_render_block', 'render_block']);
        $recorder = new BlockRecorder;
        $recorder->install();

        return [$recorder, $hooks];
    }

    it('times a block in milliseconds, and adds up the blocks of one type', function (): void {
        [$recorder, $hooks] = blockListeners();

        $hooks['pre_render_block'](null, ['blockName' => 'core/paragraph']);
        usleep(3000);
        $hooks['render_block']('a');
        $hooks['pre_render_block'](null, ['blockName' => 'core/paragraph']);
        usleep(3000);
        $hooks['render_block']('b');
        $block = $recorder->blocks()['core/paragraph'];

        expect($block['count'])->toBe(2)
            ->and($block['milliseconds'])->toBeFloat()->toBeGreaterThanOrEqual(6.0)->toBeLessThan(500.0)
            ->and($block['maxDepth'])->toBe(0);
    });

    it('names a block with no name freeform, and renders nothing it does not open', function (): void {
        [$recorder, $hooks] = blockListeners();

        $hooks['pre_render_block'](null, ['innerHTML' => 'x']);
        $hooks['render_block']('a');
        $hooks['pre_render_block']('answered', ['blockName' => 'core/skipped']);
        $hooks['pre_render_block'](null, 'not an array');
        $hooks['pre_render_block'](['pre'], 'not an array');
        $hooks['render_block']('b');

        expect(array_keys($recorder->blocks()))->toBe(['freeform']);
    });

    it('tells the greatest depth a block type was found at', function (): void {
        [$recorder, $hooks] = blockListeners();

        $hooks['pre_render_block'](null, ['blockName' => 'core/group']);
        $hooks['pre_render_block'](null, ['blockName' => 'core/group']);
        $hooks['pre_render_block'](null, ['blockName' => 'core/group']);
        $hooks['render_block']('c');
        $hooks['render_block']('b');
        $hooks['render_block']('a');
        $hooks['pre_render_block'](null, ['blockName' => 'core/group']);
        $hooks['render_block']('d');

        expect($recorder->blocks()['core/group']['maxDepth'])->toBe(2)
            ->and($recorder->blocks()['core/group']['count'])->toBe(4);
    });
});
