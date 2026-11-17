<?php

declare(strict_types=1);

use Brain\Monkey\Filters;
use Pollora\Debugbar\Recording\CapabilityRecorder;

/**
 * @param  array<string, mixed>  $allcaps
 * @param  array<int, string>  $caps
 * @param  array<int, mixed>  $args
 */
function recordCheck(CapabilityRecorder $recorder, array $allcaps, array $caps, array $args): void
{
    (new ReflectionMethod($recorder, 'record'))->invoke($recorder, $allcaps, $caps, $args);
}

it('records a check with every field the collector reads', function (): void {
    $recorder = new CapabilityRecorder;

    recordCheck($recorder, ['edit_post' => true], ['edit_post'], ['edit_post', '7', 42, 'x']);

    expect($recorder->checks())->toBe([[
        'user' => 7,
        'capability' => 'edit_post',
        'arguments' => '42, x',
        'granted' => true,
        'count' => 1,
        'frames' => [],
    ]]);
});

it('reads a check with no capability or user as an empty one for user 0', function (): void {
    $recorder = new CapabilityRecorder;

    recordCheck($recorder, [], ['read'], []);

    expect($recorder->checks()[0]['capability'])->toBe('')
        ->and($recorder->checks()[0]['user'])->toBe(0)
        ->and($recorder->checks()[0]['arguments'])->toBe('');
});

it('counts as one the checks that agree on user, capability, arguments and result, and as several the others', function (): void {
    $recorder = new CapabilityRecorder;
    $held = ['read' => true];

    recordCheck($recorder, $held, ['read'], ['read', 1]);
    recordCheck($recorder, $held, ['read'], ['read', 1]);
    recordCheck($recorder, $held, ['read'], ['read', 2]);
    recordCheck($recorder, $held, ['read'], ['read', 1, 'a']);
    recordCheck($recorder, $held, ['read'], ['edit', 1]);
    recordCheck($recorder, [], ['read'], ['read', 1]);

    expect(array_column($recorder->checks(), 'count'))->toBe([2, 1, 1, 1, 1]);
});

it('does not mix up checks whose fields run into each other', function (): void {
    $recorder = new CapabilityRecorder;
    $held = ['read' => true];

    // "1|2x|" against "12|x|", "1|a|b" against "1|ab|"
    recordCheck($recorder, $held, ['read'], ['2x', 1]);
    recordCheck($recorder, $held, ['read'], ['x', 12]);
    recordCheck($recorder, $held, ['read'], ['a', 5, 'b']);
    recordCheck($recorder, $held, ['read'], ['ab', 5]);

    expect($recorder->checks())->toHaveCount(4);
});

it('grants a check only when every capability it maps to is held, and refuses it as soon as one is not', function (): void {
    $recorder = new CapabilityRecorder;

    recordCheck($recorder, ['a' => true, 'b' => true], ['a', 'b'], ['x', 1]);
    recordCheck($recorder, ['a' => true], ['a', 'b'], ['y', 1]);
    recordCheck($recorder, ['b' => true], ['a', 'b'], ['z', 1]);
    recordCheck($recorder, ['a' => false], ['a'], ['w', 1]);

    expect(array_column($recorder->checks(), 'granted'))->toBe([true, false, false, false]);
});

it('keeps the files that led to a check, none of them empty, at most fifteen', function (): void {
    $hooks = new ArrayObject;
    Filters\expectAdded('user_has_cap')->zeroOrMoreTimes()->whenHappen(function (callable $callback) use ($hooks): void {
        $hooks['cb'] = $callback;
    });
    $recorder = new CapabilityRecorder(backtrace: true);
    $recorder->install();
    $deep = function (int $depth) use (&$deep, $hooks): void {
        $depth === 0 ? $hooks['cb'](['read' => true], ['read'], ['read', 1]) : $deep($depth - 1);
    };

    $deep(30);

    $frames = $recorder->checks()[0]['frames'];

    expect($frames)->toHaveCount(15)
        ->and(array_unique(array_column($frames, 'file')))->toContain(__FILE__)
        ->and(array_keys($frames))->toBe(range(0, 14))
        ->and(array_column($frames, 'file'))->each->not->toBe('');
});

it('drops the frames that have no file, such as an internal call', function (): void {
    $recorder = new CapabilityRecorder(backtrace: true);

    // Reflection calls record() from inside PHP: that frame has no file
    recordCheck($recorder, ['read' => true], ['read'], ['read', 1]);

    expect(array_column($recorder->checks()[0]['frames'], 'file'))->each->not->toBe('');
});
