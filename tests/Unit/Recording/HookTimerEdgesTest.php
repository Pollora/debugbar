<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use DebugBar\DataFormatter\DataFormatter;
use Pollora\Debugbar\Collectors\WpHookTimingsCollector;
use Pollora\Debugbar\Recording\HookTimer;
use Pollora\Debugbar\Recording\TimedCallback;
use Pollora\Debugbar\Support\Components;

final class HookTimerEdgesFixture
{
    public static function shout(string $text): string
    {
        return strtoupper($text).'!';
    }

    public function __invoke(string $text): string
    {
        return strrev($text);
    }

    public function __call(string $name, array $arguments): mixed
    {
        return null;
    }
}

/**
 * @param  array<string, mixed>  $callbacks  Id => callback, all at priority 10
 */
function edgeHook(string $name, array $callbacks): void
{
    $GLOBALS['wp_filter'][$name] = (object) ['callbacks' => [10 => array_map(
        static fn (mixed $callback): array => ['function' => $callback, 'accepted_args' => 1],
        $callbacks,
    )]];
}

function edgeCallback(string $hook, string $id): mixed
{
    return $GLOBALS['wp_filter'][$hook]->callbacks[10][$id]['function'];
}

afterEach(function (): void {
    unset($GLOBALS['wp_filter']);
});

it('wraps the callbacks of every hook, from the "all" action, but only for hooks named by a string', function (): void {
    $all = null;
    Actions\expectAdded('all')->once()->whenHappen(function (callable $callback) use (&$all): void {
        $all = $callback;
    });
    $timer = new HookTimer;

    $timer->install();
    $timer->install();
    edgeHook('init', ['cb' => 'strtoupper']);
    $all(42);
    $all('init');

    expect($timer->isInstalled())->toBeTrue()
        ->and(edgeCallback('init', 'cb'))->toBeInstanceOf(TimedCallback::class);
});

it('leaves alone a hook WordPress does not hold, or holds in a shape it does not know', function (): void {
    $timer = new HookTimer;

    $timer->wrap('never_registered');
    $GLOBALS['wp_filter']['odd'] = (object) ['callbacks' => 'not an array'];
    $timer->wrap('odd');
    $GLOBALS['wp_filter']['bare'] = new stdClass;
    $timer->wrap('bare');
    $GLOBALS['wp_filter'] = 'not an array';
    $timer->wrap('anything');

    expect($timer->timings())->toBe([]);
});

it('skips an entry with no function and one that is already timed', function (): void {
    $timer = new HookTimer;
    $GLOBALS['wp_filter']['init'] = (object) ['callbacks' => [10 => ['empty' => ['accepted_args' => 1]]]];
    $timer->wrap('init');
    edgeHook('init', ['cb' => 'strtoupper']);

    $timer->wrap('init');
    $timed = edgeCallback('init', 'cb');
    $timer->wrap('init');

    expect(edgeCallback('init', 'cb'))->toBe($timed);
});

it('times invokable objects and plain function names', function (): void {
    $timer = new HookTimer;
    edgeHook('title', ['invokable' => new HookTimerEdgesFixture, 'function' => 'strtoupper']);

    $timer->wrap('title');

    expect(edgeCallback('title', 'invokable')('abc'))->toBe('cba')
        ->and(edgeCallback('title', 'function')('b'))->toBe('B')
        ->and($timer->timings())->toHaveCount(2);
});

it('times a static method written as "Class::method"', function (): void {
    $timer = new HookTimer;
    edgeHook('title', ['static' => HookTimerEdgesFixture::class.'::shout']);

    $timer->wrap('title');

    expect(edgeCallback('title', 'static')('a'))->toBe('A!')
        ->and($timer->timings())->toHaveCount(1);
});

it('leaves alone what it cannot read: a missing function, a magic method, a value that is no callback', function (): void {
    $timer = new HookTimer;
    $callbacks = [
        'missing' => 'a_function_nobody_defined',
        'magic' => [new HookTimerEdgesFixture, 'answeredByCall'],
        'number' => 42,
        'half' => [new HookTimerEdgesFixture],
    ];
    edgeHook('mixed', $callbacks);

    $timer->wrap('mixed');

    foreach ($callbacks as $id => $callback) {
        expect(edgeCallback('mixed', $id))->toBe($callback);
    }
});

it('does not time its own callbacks, which would time the timer', function (): void {
    $own = new TimedCallback('strtoupper', new HookTimer, 'k');
    edgeHook('init', ['own' => [new HookTimer, 'isInstalled']]);

    (new HookTimer)->wrap('init');

    expect(edgeCallback('init', 'own'))->toBeArray()
        ->and($own('x'))->toBe('X');
});

it('ignores an end with no start, and the end of a callback it never registered', function (): void {
    $timer = new HookTimer;

    $timer->leave('nothing');
    $timer->enter();
    $timer->leave('unknown key');

    expect($timer->timings())->toBe([]);
});

it('forgets its timings on reset', function (): void {
    $timer = new HookTimer;
    edgeHook('init', ['cb' => 'strtoupper']);
    $timer->wrap('init');
    edgeCallback('init', 'cb')('a');

    $timer->reset();

    expect($timer->timings())->toBe([]);
});

it('numbers a callback that runs on several hooks, and shows an unknown file as unknown', function (): void {
    $timer = new HookTimer;
    edgeHook('first', ['cb' => 'strtoupper']);
    edgeHook('second', ['cb' => 'strtoupper']);
    $timer->wrap('first');
    $timer->wrap('second');
    edgeCallback('first', 'cb')('a');
    edgeCallback('second', 'cb')('a');

    $rows = (new WpHookTimingsCollector($timer, new Components([])))->setDataFormatter(new DataFormatter)->collect()['data']['data'];

    expect(array_keys($rows))->toBe(['strtoupper', 'strtoupper '])
        ->and(array_column($rows, 'component'))->toBe(['unknown', 'unknown']);
});

it('attributes a timed callback to the component of the file it is in', function (): void {
    $timer = new HookTimer;
    edgeHook('second', ['closure_in_this_file' => static fn (): int => 1]);
    $timer->wrap('second');
    edgeCallback('second', 'closure_in_this_file')();

    $rows = (new WpHookTimingsCollector($timer, new Components([__DIR__.'/' => ['kind' => 'plugin', 'name' => 'acme']])))
        ->setDataFormatter(new DataFormatter)->collect()['data']['data'];

    expect(array_values($rows)[0]['component'])->toBe('plugin: acme');
});

describe('a timed callback', function (): void {
    it('reads as the callback it replaces where code looks inside $wp_filter', function (): void {
        $timed = new TimedCallback(['Acme', 'run'], new HookTimer, 'k');

        expect(isset($timed[0]))->toBeTrue()
            ->and(isset($timed[5]))->toBeFalse()
            ->and($timed[1])->toBe('run')
            ->and($timed[5])->toBeNull();
    });

    it('has no array side when the callback is not an array', function (): void {
        $timed = new TimedCallback('strtoupper', new HookTimer, 'k');

        expect(isset($timed[0]))->toBeFalse()
            ->and($timed[0])->toBeNull();
    });

    it('cannot be changed through the array syntax', function (): void {
        $timed = new TimedCallback('strtoupper', new HookTimer, 'k');

        expect(function () use ($timed): void {
            $timed[0] = 'other';
        })->toThrow(LogicException::class)
            ->and(function () use ($timed): void {
                unset($timed[0]);
            })->toThrow(LogicException::class);
    });

    it('reports its time even when the callback throws', function (): void {
        $timer = new HookTimer;
        edgeHook('boom', ['cb' => static function (): void {
            throw new RuntimeException('boom');
        }]);
        $timer->wrap('boom');

        expect(fn () => edgeCallback('boom', 'cb')())->toThrow(RuntimeException::class)
            ->and($timer->timings()[0]['calls'])->toBe(1);
    });
});

it('keeps, per timed callback, the hook, priority, callback, file, calls and the longest single run', function (): void {
    $timer = new HookTimer;
    $callback = static function (int $microseconds): void {
        usleep($microseconds);
    };
    $GLOBALS['wp_filter']['slowly'] = (object) ['callbacks' => [7 => ['cb' => ['function' => $callback, 'accepted_args' => 1]]]];

    $timer->wrap('slowly');
    $timed = $GLOBALS['wp_filter']['slowly']->callbacks[7]['cb']['function'];
    $timed(4000);
    $timed(0);
    $timed(1000);

    $timing = $timer->timings()[0];

    expect($timer->timings())->toHaveCount(1)
        ->and($timing['hook'])->toBe('slowly')
        ->and($timing['priority'])->toBe(7)
        ->and($timing['callback'])->toBe($callback)
        ->and($timing['file'])->toBe(__FILE__)
        ->and($timing['calls'])->toBe(3)
        ->and($timing['max'])->toBeGreaterThanOrEqual(4_000_000)
        ->and($timing['max'])->toBeLessThanOrEqual($timing['total'])
        ->and($timing['total'])->toBeGreaterThan($timing['max'])
        ->and($timing['self'])->toBe($timing['total']);
});

it('lists only the callbacks that actually ran, as a list', function (): void {
    $timer = new HookTimer;
    edgeHook('init', ['ran' => 'strtoupper', 'idle' => 'strtolower', 'also' => 'strrev']);
    $timer->wrap('init');
    edgeCallback('init', 'also')('x');

    expect(array_keys($timer->timings()))->toBe([0])
        ->and($timer->timings()[0]['calls'])->toBe(1);
});

it('does not time a callback with a by-reference parameter, nor leaves the hook half wrapped', function (): void {
    $timer = new HookTimer;
    $byReference = static function (array &$items): void {
        $items[] = 1;
    };
    edgeHook('mixed', ['ref' => $byReference, 'plain' => 'strtoupper']);

    $timer->wrap('mixed');

    expect(edgeCallback('mixed', 'ref'))->toBe($byReference)
        ->and(edgeCallback('mixed', 'plain'))->toBeInstanceOf(TimedCallback::class);
});

it('keeps apart the callbacks whose hook, priority and id only differ where the key joins them', function (): void {
    $timer = new HookTimer;
    $GLOBALS['wp_filter'] = [
        'a1' => (object) ['callbacks' => [0 => ['cb' => ['function' => 'strtoupper', 'accepted_args' => 1]]]],
        'a' => (object) ['callbacks' => [
            10 => ['cb' => ['function' => 'strtoupper', 'accepted_args' => 1]],
            1 => ['2x' => ['function' => 'strtolower', 'accepted_args' => 1]],
            12 => ['x' => ['function' => 'strrev', 'accepted_args' => 1]],
        ]],
        'b' => (object) ['callbacks' => [
            1 => ['same' => ['function' => 'strtoupper', 'accepted_args' => 1]],
            2 => ['same' => ['function' => 'strtolower', 'accepted_args' => 1]],
        ]],
    ];

    foreach (['a1', 'a', 'b'] as $hook) {
        $timer->wrap($hook);
    }

    foreach ($GLOBALS['wp_filter'] as $hook) {
        foreach ($hook->callbacks as $row) {
            foreach ($row as $entry) {
                $entry['function']('x');
            }
        }
    }

    expect($timer->timings())->toHaveCount(6)
        ->and(array_sum(array_column($timer->timings(), 'calls')))->toBe(6);
});

it('times the callbacks that follow one it skipped, in the same row', function (): void {
    $timer = new HookTimer;
    $byReference = static function (array &$items): void {
        $items[] = 1;
    };
    $GLOBALS['wp_filter']['init'] = (object) ['callbacks' => [10 => [
        'noise' => ['accepted_args' => 1],
        'ref' => ['function' => $byReference, 'accepted_args' => 1],
        'timed' => ['function' => new TimedCallback('strtoupper', $timer, 'k'), 'accepted_args' => 1],
        'plain' => ['function' => 'strtolower', 'accepted_args' => 1],
    ]]];

    $timer->wrap('init');

    expect($GLOBALS['wp_filter']['init']->callbacks[10]['plain']['function'])->toBeInstanceOf(TimedCallback::class);
});

it('ignores a priority row that is not a list of callbacks', function (): void {
    $timer = new HookTimer;
    $GLOBALS['wp_filter']['init'] = (object) ['callbacks' => [10 => 'junk', 20 => ['cb' => ['function' => 'strtoupper', 'accepted_args' => 1]]]];

    $timer->wrap('init');

    expect($GLOBALS['wp_filter']['init']->callbacks[10])->toBe('junk')
        ->and($GLOBALS['wp_filter']['init']->callbacks[20]['cb']['function'])->toBeInstanceOf(TimedCallback::class);
});

it('keeps its first answer about a callback id, even when the function exists later', function (): void {
    $timer = new HookTimer;
    edgeHook('first', ['late' => 'hook_timer_defined_later']);
    $timer->wrap('first');

    eval('function hook_timer_defined_later(string $text): string { return $text; }');
    edgeHook('second', ['late' => 'hook_timer_defined_later']);
    $timer->wrap('second');

    expect(edgeCallback('first', 'late'))->toBe('hook_timer_defined_later')
        ->and(edgeCallback('second', 'late'))->toBe('hook_timer_defined_later');
});

it('ignores the end of a callback that never started, even one it knows', function (): void {
    $timer = new HookTimer;
    edgeHook('init', ['cb' => 'strtoupper']);
    $timer->wrap('init');

    $timer->leave('init|10|cb');

    expect($timer->timings())->toBe([]);
});

it('times a callback written as a class name and a method', function (): void {
    $timer = new HookTimer;
    edgeHook('title', ['static' => [HookTimerEdgesFixture::class, 'shout']]);

    $timer->wrap('title');

    expect(edgeCallback('title', 'static'))->toBeInstanceOf(TimedCallback::class)
        ->and(edgeCallback('title', 'static')('a'))->toBe('A!');
});

it('takes the time of nested callbacks off the own time of the one that fired them, whatever the order they end in', function (): void {
    $timer = new HookTimer;
    $GLOBALS['wp_filter']['outer'] = (object) ['callbacks' => [10 => ['o' => ['function' => static function (): void {
        usleep(2000);
        $inner = $GLOBALS['wp_filter']['inner']->callbacks[10];
        $inner['i1']['function']();
        $inner['i2']['function']();
    }, 'accepted_args' => 1]]]];
    $GLOBALS['wp_filter']['inner'] = (object) ['callbacks' => [10 => [
        'i1' => ['function' => static fn (): null => usleep(1000), 'accepted_args' => 1],
        'i2' => ['function' => static fn (): null => usleep(3000), 'accepted_args' => 1],
    ]]];
    $timer->wrap('outer');
    $timer->wrap('inner');

    $GLOBALS['wp_filter']['outer']->callbacks[10]['o']['function']();

    $byId = array_column($timer->timings(), null, 'hook');
    $inner = array_values(array_filter($timer->timings(), static fn (array $t): bool => $t['hook'] === 'inner'));
    $outer = $byId['outer'];

    expect($outer['self'] + $inner[0]['total'] + $inner[1]['total'])->toBe($outer['total'])
        ->and($outer['self'])->toBeGreaterThanOrEqual(2_000_000)
        ->and($outer['total'])->toBeGreaterThanOrEqual(6_000_000);
});

it('shows the milliseconds of each callback with two decimals, and its hook with its priority', function (): void {
    $timer = new HookTimer;
    edgeHook('slow', ['cb' => 'strtoupper']);
    $timer->wrap('slow');
    edgeCallback('slow', 'cb')('a');

    // Times are set directly: a real clock would make the text vary
    $timings = new ReflectionProperty($timer, 'timings');
    $timings->setValue($timer, array_map(static fn (array $t): array => [...$t, 'self' => 1_234_567, 'total' => 2_000_000, 'calls' => 4], $timings->getValue($timer)));

    $rows = (new WpHookTimingsCollector($timer, new Components([]), limit: 5))->setDataFormatter(new DataFormatter)->collect()['data']['data'];

    expect($rows)->toBe(['strtoupper' => ['self' => '1.23 ms', 'total' => '2.00 ms', 'calls' => 4, 'hook' => 'slow @10', 'component' => 'unknown']]);
});

it('shows at least one row even when the limit is zero', function (): void {
    $timer = new HookTimer;
    edgeHook('init', ['a' => 'strtoupper', 'b' => 'strtolower']);
    $timer->wrap('init');
    edgeCallback('init', 'a')('x');
    edgeCallback('init', 'b')('x');

    $rows = (new WpHookTimingsCollector($timer, new Components([]), limit: 0))->setDataFormatter(new DataFormatter)->collect()['data']['data'];

    expect($rows)->toHaveCount(1);
});
