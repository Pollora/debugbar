<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use DebugBar\DataCollector\TimeDataCollector;
use Pollora\Debugbar\Collectors\WpTimelineCollector;
use Pollora\Debugbar\Recording\RequestRecorder;

it("hands WordPress's phases to Debugbar's timeline when the bar collects", function (): void {
    $callbacks = [];
    Actions\expectAdded('wp_loaded')->zeroOrMoreTimes()->whenHappen(function (callable $callback, int $priority) use (&$callbacks): void {
        $callbacks[$priority] = $callback;
    });

    $recorder = new RequestRecorder;
    $recorder->install();
    $callbacks[PHP_INT_MIN]();
    $callbacks[PHP_INT_MAX]();

    $time = new TimeDataCollector(microtime(true) - 1);
    (new WpTimelineCollector($recorder, $time))->collect();

    $labels = array_column($time->collect()['measures'], 'label');

    expect($labels)->toContain('WordPress loading')
        ->and($labels)->toContain('wp_loaded callbacks');
});
