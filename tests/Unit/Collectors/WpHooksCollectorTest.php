<?php

declare(strict_types=1);

use Pollora\Debugbar\Collectors\WpHooksCollector;
use Pollora\Debugbar\Recording\HookTimer;
use Pollora\Debugbar\Recording\RequestRecorder;
use Pollora\Debugbar\Recording\TimedCallback;

final class FakePolloraHooks
{
    public function all(): array
    {
        return ['init' => [['hook' => 'init', 'callback' => [new ArrayObject, 'count'], 'priority' => 5, 'args' => 0]]];
    }
}

beforeEach(function (): void {
    $GLOBALS['wp_actions'] = ['muplugins_loaded' => 1, 'init' => 1, 'wp_head' => 2];
    $GLOBALS['wp_filter'] = [
        'init' => (object) ['callbacks' => [5 => ['a' => [], 'b' => []], 10 => ['c' => []]]],
    ];
});

afterEach(function (): void {
    unset($GLOBALS['wp_actions'], $GLOBALS['wp_filter']);
});

it('lists the actions that ran, in order, with their callbacks', function (): void {
    $rows = (new WpHooksCollector(new RequestRecorder))->collect()['data']['data'];

    expect(array_keys($rows))->toBe(['muplugins_loaded', 'init', 'wp_head'])
        ->and($rows['init']['callbacks'])->toBe(3)
        ->and($rows['wp_head']['calls'])->toBe(2);
});

it('names the callbacks Pollora registered, with their priority', function (): void {
    $rows = (new WpHooksCollector(new RequestRecorder, [new FakePolloraHooks]))->collect()['data']['data'];

    expect($rows['init']['pollora'])->toBe('ArrayObject::count @5')
        ->and($rows['wp_head']['pollora'])->toBe('');
});

final class WpHooksClosureOwner
{
    public function closure(): Closure
    {
        return fn (): null => null;
    }
}

it('describes callbacks the way a reader recognises them', function (): void {
    expect(WpHooksCollector::describe('wp_head'))->toBe('wp_head')
        ->and(WpHooksCollector::describe(['Acme\\Cart', 'boot']))->toBe('Acme\\Cart::boot')
        ->and(WpHooksCollector::describe((new WpHooksClosureOwner)->closure()))->toMatch('/^closure in WpHooksClosureOwner \\(WpHooksCollectorTest\\.php:\\d+\\)$/')
        ->and(WpHooksCollector::describe((new ArrayObject)->count(...)))->toBe('ArrayObject::count');
});

it('does not name the class a closure was merely included from', function (): void {
    $loader = new class
    {
        public function load(): Closure
        {
            return require __DIR__.'/../../Fixtures/included-closure.php';
        }
    };

    expect(WpHooksCollector::describe($loader->load()))->toMatch('/^closure \\(included-closure\\.php:\\d+\\)$/');
});

it('describes an invokable object by its class', function (): void {
    expect(WpHooksCollector::describe(new ArrayObject))->toBe('ArrayObject')
        ->and(WpHooksCollector::describe(42))->toBe('unknown');
});

it('shows each hook with exactly its calls, callbacks and Pollora registrations', function (): void {
    $rows = (new WpHooksCollector(new RequestRecorder, [new FakePolloraHooks]))->collect()['data']['data'];

    expect($rows['init'])->toBe(['calls' => 1, 'callbacks' => 3, 'pollora' => 'ArrayObject::count @5'])
        ->and($rows['wp_head'])->toBe(['calls' => 2, 'callbacks' => 0, 'pollora' => ''])
        ->and($rows['muplugins_loaded'])->toBe(['calls' => 1, 'callbacks' => 0, 'pollora' => '']);
});

it('prefers the counts the recorder kept, filters included, over $wp_actions', function (): void {
    $recorded = recordedCalls(['the_content' => 3, 'init' => 1]);

    $rows = (new WpHooksCollector($recorded))->collect()['data']['data'];

    expect(array_keys($rows))->toBe(['the_content', 'init'])
        ->and($rows['the_content']['calls'])->toBe(3);
});

it('shows nothing when WordPress kept no action counts', function (): void {
    $GLOBALS['wp_actions'] = 'broken';

    expect((new WpHooksCollector(new RequestRecorder))->collect()['data']['data'])->toBe([]);
});

it('counts the callbacks of a hook across priorities, and skips a hook held in an unknown shape', function (): void {
    $GLOBALS['wp_filter'] = [
        'init' => (object) ['callbacks' => [1 => ['a' => []], 2 => ['b' => [], 'c' => []], 3 => 'one']],
        'wp_head' => 'not an object',
    ];

    $rows = (new WpHooksCollector(new RequestRecorder))->collect()['data']['data'];

    expect($rows['init']['callbacks'])->toBe(4)
        ->and($rows['wp_head']['callbacks'])->toBe(0);

    $GLOBALS['wp_filter'] = 'broken';

    expect((new WpHooksCollector(new RequestRecorder))->collect()['data']['data']['init']['callbacks'])->toBe(0);
});

final class FakeFluentHooks
{
    public function all(): array
    {
        return [
            'wp_head' => [
                ['handler' => 'first_handler', 'priority' => PHP_INT_MIN],
                ['callback' => 'last_callback', 'priority' => PHP_INT_MAX],
                ['callback' => 'default_priority'],
                ['priority' => 3],
            ],
        ];
    }
}

final class FakeHooksWithoutAll {}

it('names Pollora registrations by handler or callback, with first, last or numbered priorities', function (): void {
    $rows = (new WpHooksCollector(new RequestRecorder, [new FakeHooksWithoutAll, new FakeFluentHooks, new FakePolloraHooks]))->collect()['data']['data'];

    expect($rows['wp_head']['pollora'])->toBe('first_handler @first, last_callback @last, default_priority @10, unknown @3')
        ->and($rows['init']['pollora'])->toBe('ArrayObject::count @5');
});

it('names a timed callback as the callback it wraps, and an array with an object by its class', function (): void {
    $timed = new TimedCallback('wp_head', new HookTimer, 'k');

    expect(WpHooksCollector::describe($timed))->toBe('wp_head')
        ->and(WpHooksCollector::describe([new ArrayObject, 'count']))->toBe('ArrayObject::count')
        ->and(WpHooksCollector::describe(['Acme', 'run']))->toBe('Acme::run')
        ->and(WpHooksCollector::describe([new ArrayObject]))->toBe('unknown')
        ->and(WpHooksCollector::describe(null))->toBe('unknown');
});

it('names a closure by the function it was made from, with or without the class it is written in', function (): void {
    $scoped = (new WpHooksClosureOwner)->closure();

    expect(WpHooksCollector::describe(strtoupper(...)))->toBe('strtoupper')
        ->and(WpHooksCollector::describe((new ArrayObject)->count(...)))->toBe('ArrayObject::count')
        ->and(WpHooksCollector::describe($scoped))->toMatch('/^closure in WpHooksClosureOwner \(WpHooksCollectorTest\.php:\d+\)$/');
});

function recordedCalls(array $calls): RequestRecorder
{
    $recorder = new RequestRecorder;
    $captured = null;
    Brain\Monkey\Actions\expectAdded('all')->once()->whenHappen(function (callable $callback) use (&$captured): void {
        $captured = $callback;
    });
    $recorder->install(true);

    foreach ($calls as $hook => $count) {
        for ($i = 0; $i < $count; $i++) {
            $captured($hook);
        }
    }

    return $recorder;
}
