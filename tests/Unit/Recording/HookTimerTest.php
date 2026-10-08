<?php

declare(strict_types=1);

use DebugBar\DataFormatter\DataFormatter;
use Pollora\Debugbar\Collectors\WpHookTimingsCollector;
use Pollora\Debugbar\Recording\HookTimer;
use Pollora\Debugbar\Recording\TimedCallback;
use Pollora\Debugbar\Support\Components;

/**
 * A hook as WordPress keeps it in `$wp_filter`: callbacks by priority, then by id.
 *
 * @param  array<int, array<string, mixed>>  $callbacks  Priority => [id => callback]
 */
function hookWith(string $name, array $callbacks): void
{
    $entries = [];

    foreach ($callbacks as $priority => $byId) {
        foreach ($byId as $id => $callback) {
            $entries[$priority][$id] = ['function' => $callback, 'accepted_args' => 1];
        }
    }

    $GLOBALS['wp_filter'][$name] = (object) ['callbacks' => $entries];
}

function callbackOf(string $hook, int $priority, string $id): mixed
{
    return $GLOBALS['wp_filter'][$hook]->callbacks[$priority][$id]['function'];
}

final class HookTimerFixture
{
    public function title(string $title): string
    {
        return strtoupper($title);
    }

    public function byReference(array &$items): void
    {
        $items[] = 'added';
    }
}

afterEach(function (): void {
    unset($GLOBALS['wp_filter']);
});

it('times each callback under the key WordPress gave it, and returns what it returns', function (): void {
    hookWith('the_title', [10 => ['fixture_title' => [new HookTimerFixture, 'title']]]);
    $timer = new HookTimer;

    $timer->wrap('the_title');
    $callback = callbackOf('the_title', 10, 'fixture_title');

    expect($callback)->toBeInstanceOf(TimedCallback::class)
        ->and($callback('hello'))->toBe('HELLO')
        ->and($timer->timings())->toHaveCount(1)
        ->and($timer->timings()[0]['calls'])->toBe(1)
        ->and($timer->timings()[0]['hook'])->toBe('the_title');
});

it('keeps an array callback readable where code looks inside $wp_filter', function (): void {
    $fixture = new HookTimerFixture;
    hookWith('the_title', [10 => ['fixture_title' => [$fixture, 'title']]]);

    (new HookTimer)->wrap('the_title');
    $callback = callbackOf('the_title', 10, 'fixture_title');

    expect($callback[0])->toBe($fixture)
        ->and($callback[1])->toBe('title');
});

it('counts the time spent in nested hooks in the total, not in the own time', function (): void {
    $timer = new HookTimer;
    hookWith('inner', [10 => ['inner_cb' => function (): void {
        usleep(6000);
    }]]);
    hookWith('outer', [10 => ['outer_cb' => function (): void {
        usleep(1000);
        callbackOf('inner', 10, 'inner_cb')();
    }]]);

    $timer->wrap('inner');
    $timer->wrap('outer');
    callbackOf('outer', 10, 'outer_cb')();

    $byHook = array_column($timer->timings(), null, 'hook');

    expect($byHook['outer']['total'])->toBeGreaterThan(6_000_000)
        ->and($byHook['outer']['self'])->toBeLessThan(6_000_000)
        ->and($byHook['inner']['self'])->toBeGreaterThan(6_000_000);
});

it('leaves callbacks taking parameters by reference as they are', function (): void {
    $fixture = new HookTimerFixture;
    hookWith('collect', [10 => ['by_ref' => [$fixture, 'byReference']]]);

    (new HookTimer)->wrap('collect');

    expect(callbackOf('collect', 10, 'by_ref'))->toBe([$fixture, 'byReference']);
});

it('times a callback added again in the same row', function (): void {
    $title = [new HookTimerFixture, 'title'];
    hookWith('the_title', [10 => ['fixture_title' => $title]]);
    $timer = new HookTimer;

    $timer->wrap('the_title');
    callbackOf('the_title', 10, 'fixture_title')('a');

    // add_filter() with the same callback writes the raw callback back under its key
    $GLOBALS['wp_filter']['the_title']->callbacks[10]['fixture_title']['function'] = $title;
    $timer->wrap('the_title');
    callbackOf('the_title', 10, 'fixture_title')('b');

    expect($timer->timings())->toHaveCount(1)
        ->and($timer->timings()[0]['calls'])->toBe(2);
});

it('lists the slowest callbacks first, up to the limit', function (): void {
    $timer = new HookTimer;
    hookWith('fast', [10 => ['fast_cb' => 'strtoupper']]);
    hookWith('slow', [5 => ['slow_cb' => function (): void {
        usleep(3000);
    }]]);

    $timer->wrap('fast');
    $timer->wrap('slow');
    callbackOf('fast', 10, 'fast_cb')('a');
    callbackOf('slow', 5, 'slow_cb')();

    $rows = (new WpHookTimingsCollector($timer, new Components([]), limit: 1))
        ->setDataFormatter(new DataFormatter)
        ->collect()['data']['data'];

    expect($rows)->toHaveCount(1)
        ->and(array_values($rows)[0]['hook'])->toBe('slow @5');
});
