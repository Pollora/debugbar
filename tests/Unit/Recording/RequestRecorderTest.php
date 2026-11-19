<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Pollora\Debugbar\Recording\RequestRecorder;

/**
 * Brain Monkey records registrations without running them, so the callbacks
 * are captured here and called by hand, as WordPress would.
 *
 * @return array<string, array<int, callable>>
 */
function captureHooks(): ArrayObject
{
    $captured = new ArrayObject;

    foreach (RequestRecorder::NOTABLE_ACTIONS as $action) {
        Actions\expectAdded($action)->zeroOrMoreTimes()->whenHappen(function (callable $callback, int $priority) use ($captured, $action): void {
            $captured["{$action}@{$priority}"] = $callback;
        });
    }

    Filters\expectAdded('single_template_hierarchy')->zeroOrMoreTimes()->whenHappen(function (callable $callback) use ($captured): void {
        $captured['single_template_hierarchy'] = $callback;
    });

    Filters\expectAdded('template_include')->zeroOrMoreTimes()->whenHappen(function (callable $callback) use ($captured): void {
        $captured['template_include'] = $callback;
    });

    Actions\expectAdded('all')->zeroOrMoreTimes()->whenHappen(function (callable $callback) use ($captured): void {
        $captured['all'] = $callback;
    });

    return $captured;
}

it('times each notable action from its first callback to its last', function (): void {
    $hooks = captureHooks();
    $recorder = new RequestRecorder;
    $recorder->install();

    $hooks['init@'.PHP_INT_MIN]();
    usleep(1000);
    $hooks['init@'.PHP_INT_MAX]();

    $phase = $recorder->phases()['init'];

    expect($phase['end'])->toBeGreaterThan($phase['start'])
        ->and($recorder->bootedAt())->toBeLessThanOrEqual($phase['start']);
});

it('keeps the hierarchy WordPress built and the template it settled on, unchanged', function (): void {
    $hooks = captureHooks();
    $recorder = new RequestRecorder;
    $recorder->install();

    $returned = $hooks['single_template_hierarchy'](['single-post.php', 'single.php']);
    $template = $hooks['template_include']('/theme/single.blade.php');

    expect($returned)->toBe(['single-post.php', 'single.php'])
        ->and($template)->toBe('/theme/single.blade.php')
        ->and($recorder->hierarchies())->toBe(['single' => ['single-post.php', 'single.php']])
        ->and($recorder->template())->toBe('/theme/single.blade.php');
});

it('counts every hook call only when asked to', function (): void {
    $hooks = captureHooks();
    $recorder = new RequestRecorder;
    $recorder->install(countAllHooks: true);

    $hooks['all']('the_content');
    $hooks['all']('the_content');

    expect($recorder->hookCalls())->toBe(['the_content' => 2]);
});

it('does not listen to every hook by default', function (): void {
    $hooks = captureHooks();
    (new RequestRecorder)->install();

    expect(isset($hooks['all']))->toBeFalse();
});

it('installs once', function (): void {
    Actions\expectAdded('init')->twice();
    captureHooks();

    $recorder = new RequestRecorder;
    $recorder->install();
    $recorder->install();

    expect($recorder->isInstalled())->toBeTrue();
});

it('counts the calls of every hook, and answers the counts only once there are some', function (): void {
    $hooks = captureHooks();
    $recorder = new RequestRecorder;
    $recorder->install(true);

    expect($recorder->hookCalls())->toBeNull();

    $hooks['all']('init');
    $hooks['all']('init');
    $hooks['all']('wp_head');

    expect($recorder->hookCalls())->toBe(['init' => 2, 'wp_head' => 1]);
});

it('registers its hook counter with the default priority and one argument', function (): void {
    $arguments = null;
    Actions\expectAdded('all')->once()->whenHappen(function (callable $callback, int $priority, int $accepted) use (&$arguments): void {
        $arguments = [$priority, $accepted];
    });

    (new RequestRecorder)->install(true);

    expect($arguments)->toBe([10, 1]);
});

it('keeps the first start of a phase, moves its end on each end, and ignores an end with no start', function (): void {
    $hooks = captureHooks();
    $recorder = new RequestRecorder;
    $recorder->install();

    $hooks['init@'.PHP_INT_MAX]();

    expect($recorder->phases())->toBe([]);

    $hooks['init@'.PHP_INT_MIN]();
    $first = $recorder->phases()['init'];
    usleep(1000);
    $hooks['init@'.PHP_INT_MIN]();

    expect($recorder->phases()['init'])->toBe($first)
        ->and($first['end'])->toBeGreaterThanOrEqual($first['start'])
        ->and($first['end'] - $first['start'])->toBeLessThan(0.5)
        ->and($first['start'])->toBeFloat()->toBeGreaterThan(1_000_000_000);

    usleep(1000);
    $hooks['init@'.PHP_INT_MAX]();

    expect($recorder->phases()['init']['end'])->toBeGreaterThan($first['end'])
        ->and($recorder->phases()['init']['start'])->toBe($first['start']);
});

it('keeps only the templates that are scalars, as strings and in order', function (): void {
    $hooks = captureHooks();
    $recorder = new RequestRecorder;
    $recorder->install();

    $hooks['single_template_hierarchy'](['single.php', 5, ['nested'], null, 'index.php']);
    expect($recorder->hierarchies())->toBe(['single' => ['single.php', '5', 'index.php']]);

    $hooks['single_template_hierarchy']('not a list');

    expect($recorder->hierarchies())->toBe(['single' => ['single.php', '5', 'index.php']]);
});

it('keeps the last template WordPress settled on, and ignores one that is empty or not a string', function (): void {
    $hooks = captureHooks();
    $recorder = new RequestRecorder;
    $recorder->install();

    $hooks['template_include']('/a.php');
    $hooks['template_include']('');
    $hooks['template_include'](false);

    expect($recorder->template())->toBe('/a.php');
});
