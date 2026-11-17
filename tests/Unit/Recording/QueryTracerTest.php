<?php

declare(strict_types=1);

use Brain\Monkey\Filters;
use Pollora\Debugbar\Recording\QueryTracer;
use Pollora\Debugbar\Support\Components;

/**
 * @return array<string, callable>
 */
function tracerHooks(QueryTracer $tracer): ArrayObject
{
    $hooks = new ArrayObject;

    foreach (['log_query_custom_data', 'query'] as $filter) {
        Filters\expectAdded($filter)->zeroOrMoreTimes()->whenHappen(function (callable $callback) use ($hooks, $filter): void {
            $hooks[$filter] = $callback;
        });
    }

    $tracer->install();

    return $hooks;
}

beforeEach(function (): void {
    $GLOBALS['wpdb'] = (object) ['queries' => [], 'last_error' => '', 'rows_affected' => 0, 'num_rows' => 0];
});

afterEach(function (): void {
    unset($GLOBALS['wpdb']);
});

it('adds the backtrace to the data WordPress saves with a query', function (): void {
    $hooks = tracerHooks(new QueryTracer);

    $data = $hooks['log_query_custom_data']([]);

    expect($data[QueryTracer::KEY]['frames'])->not->toBeEmpty()
        ->and($data[QueryTracer::KEY]['frames'][0])->toHaveKeys(['file', 'line', 'call']);
});

it('keeps no backtrace past the soft limit', function (): void {
    $GLOBALS['wpdb']->queries = array_fill(0, 3, ['SELECT 1', 0.1, '', 0.0, []]);
    $hooks = tracerHooks(new QueryTracer(softLimit: 2));

    expect($hooks['log_query_custom_data']([])[QueryTracer::KEY]['frames'])->toBe([]);
});

it("copies the previous query's error and rows when the next one starts", function (): void {
    $hooks = tracerHooks(new QueryTracer);
    $GLOBALS['wpdb']->queries[] = ['SELECT nope', 0.1, '', 0.0, [QueryTracer::KEY => ['frames' => []]]];
    $GLOBALS['wpdb']->last_error = "Table 'nope' doesn't exist";
    $GLOBALS['wpdb']->num_rows = 0;

    $query = $hooks['query']('SELECT 2');

    expect($query)->toBe('SELECT 2')
        ->and($GLOBALS['wpdb']->queries[0][4][QueryTracer::KEY]['error'])->toBe("Table 'nope' doesn't exist")
        ->and($GLOBALS['wpdb']->queries[0][4][QueryTracer::KEY]['rows'])->toBe(0);
});

it('completes a query once, so a later flush does not overwrite it', function (): void {
    $tracer = new QueryTracer;
    $GLOBALS['wpdb']->queries[] = ['UPDATE x', 0.1, '', 0.0, [QueryTracer::KEY => ['frames' => []]]];
    $GLOBALS['wpdb']->rows_affected = 3;

    $tracer->completePrevious();
    $GLOBALS['wpdb']->rows_affected = 0;
    $GLOBALS['wpdb']->last_error = 'later';
    $tracer->completePrevious();

    expect($GLOBALS['wpdb']->queries[0][4][QueryTracer::KEY])->toMatchArray(['rows' => 3, 'error' => null]);
});

it('attributes a query from its whole trace but keeps only the innermost frames to show', function (): void {
    $components = new Components([dirname(__DIR__, 3).'/' => ['kind' => 'app', 'name' => '']]);
    $hooks = tracerHooks(new QueryTracer(components: $components));

    $data = $hooks['log_query_custom_data']([])[QueryTracer::KEY];

    expect(count($data['frames']))->toBeLessThanOrEqual(10)
        ->and($data['component'])->toBe('app');
});

final class TracerCaller
{
    public function run(callable $callback): mixed
    {
        return $callback();
    }

    public static function runStatic(callable $callback): mixed
    {
        return $callback();
    }
}

function tracerTraceFrom(callable $wrap): array
{
    $hooks = tracerHooks(new QueryTracer);

    return $wrap(static fn (): array => $hooks['log_query_custom_data']([])[QueryTracer::KEY]['frames']);
}

it('keeps the line and call of each frame, innermost first', function (): void {
    $frames = tracerTraceFrom(fn (callable $trace): array => (new TracerCaller)->run($trace));
    $calls = array_column($frames, 'call');

    expect($frames[0]['file'])->toBe(__FILE__)
        ->and($frames[0]['line'])->toBeInt()->toBeGreaterThan(0)
        ->and(array_search('TracerCaller->run', $calls, true))->toBeInt()
        ->and(array_search('TracerCaller->run', $calls, true))->toBeGreaterThan(0)
        ->and($frames[array_search('TracerCaller->run', $calls, true)]['file'])->toBe(__FILE__);
});

it('leaves out wpdb, WP_Hook and the files of WordPress\'s plugin API and database layer', function (): void {
    require_once __DIR__.'/../../Fixtures/wp-includes/plugin.php';
    require_once __DIR__.'/../../Fixtures/class-wpdb.php';

    if (! class_exists('wpdb')) {
        eval('final class wpdb { public function run(callable $c): mixed { return $c(); } }');
    }

    if (! class_exists('WP_Hook')) {
        eval('final class WP_Hook { public function run(callable $c): mixed { return $c(); } }');
    }

    $hooks = tracerHooks(new QueryTracer);
    $trace = static fn (): array => $hooks['log_query_custom_data']([])[QueryTracer::KEY]['frames'];
    $frames = tracer_fixture_apply(fn (): array => tracer_fixture_wpdb(fn (): array => (new wpdb)->run(fn (): array => (new WP_Hook)->run($trace))));

    $files = array_column($frames, 'file');
    $calls = array_column($frames, 'call');

    expect($files)->each->not->toEndWith('/wp-includes/plugin.php')
        ->and($files)->each->not->toEndWith('/class-wpdb.php')
        ->and($calls)->toContain('tracer_fixture_apply')
        ->and($calls)->toContain('tracer_fixture_wpdb')
        ->and($calls)->not->toContain('wpdb->run')
        ->and($calls)->not->toContain('WP_Hook->run');
});

it('keeps the data WordPress already had, and starts from nothing when it had none', function (): void {
    $hooks = tracerHooks(new QueryTracer);

    expect($hooks['log_query_custom_data'](['other' => 1]))->toHaveKeys(['other', QueryTracer::KEY])
        ->and($hooks['log_query_custom_data'](['other' => 1])['other'])->toBe(1)
        ->and(array_keys($hooks['log_query_custom_data']('junk')))->toBe([QueryTracer::KEY]);
});

it('keeps the backtrace of the queries below the soft limit, and drops it from the limit on', function (): void {
    $GLOBALS['wpdb']->queries = [['a', 0.1, '', 0.0, []]];
    $hooks = tracerHooks(new QueryTracer(softLimit: 2));

    expect($hooks['log_query_custom_data']([])[QueryTracer::KEY]['frames'])->not->toBe([]);

    $GLOBALS['wpdb']->queries[] = ['b', 0.1, '', 0.0, []];

    expect($hooks['log_query_custom_data']([])[QueryTracer::KEY])->toBe(['frames' => []]);
});

it('traces from the first query when WordPress keeps no list of them', function (): void {
    $hooks = tracerHooks(new QueryTracer(softLimit: 1));

    $GLOBALS['wpdb'] = (object) ['queries' => 'none'];

    expect($hooks['log_query_custom_data']([])[QueryTracer::KEY]['frames'])->not->toBe([]);

    $GLOBALS['wpdb'] = null;

    expect($hooks['log_query_custom_data']([])[QueryTracer::KEY]['frames'])->not->toBe([]);

    $GLOBALS['wpdb'] = (object) [];

    expect($hooks['log_query_custom_data']([])[QueryTracer::KEY]['frames'])->not->toBe([]);
});

it('completes the last query only, and not an earlier one', function (): void {
    $tracer = new QueryTracer;
    $GLOBALS['wpdb']->queries = [
        ['a', 0.1, '', 0.0, [QueryTracer::KEY => ['frames' => []]]],
        ['b', 0.1, '', 0.0, [QueryTracer::KEY => ['frames' => []]]],
    ];
    $GLOBALS['wpdb']->rows_affected = 2;

    $tracer->completePrevious();

    expect($GLOBALS['wpdb']->queries[0][4][QueryTracer::KEY])->toBe(['frames' => []])
        ->and($GLOBALS['wpdb']->queries[1][4][QueryTracer::KEY])->toBe(['frames' => [], 'error' => null, 'rows' => 2]);
});

it('leaves a query with no traced data alone', function (): void {
    $tracer = new QueryTracer;
    $GLOBALS['wpdb']->queries = [['a', 0.1, '', 0.0, []], ['b', 0.1, '', 0.0, 'junk']];

    $tracer->completePrevious();

    expect($GLOBALS['wpdb']->queries)->toBe([['a', 0.1, '', 0.0, []], ['b', 0.1, '', 0.0, 'junk']]);

    $GLOBALS['wpdb']->queries = [['a', 0.1, '', 0.0, [QueryTracer::KEY => ['frames' => []]]], ['b', 0.1, '', 0.0, []]];

    $tracer->completePrevious();

    expect($GLOBALS['wpdb']->queries[0][4])->toBe([QueryTracer::KEY => ['frames' => []]]);
});

it('reports the error WordPress kept, and none when it is empty or not a string', function (): void {
    $tracer = new QueryTracer;

    foreach ([['Deadlock', 'Deadlock'], ['', null], [5, null], [null, null]] as [$lastError, $expected]) {
        $GLOBALS['wpdb']->queries = [['a', 0.1, '', 0.0, [QueryTracer::KEY => ['frames' => []]]]];
        $GLOBALS['wpdb']->last_error = $lastError;

        $tracer->completePrevious();

        expect($GLOBALS['wpdb']->queries[0][4][QueryTracer::KEY]['error'])->toBe($expected);
    }
});

it('reports the rows a query affected, else the rows it returned, else none', function (): void {
    $cases = [
        'affected' => [['rows_affected' => 3, 'num_rows' => 9], 3],
        'one affected' => [['rows_affected' => 1, 'num_rows' => 9], 1],
        'numeric strings' => [['rows_affected' => '2', 'num_rows' => 9], 2],
        'returned' => [['rows_affected' => 0, 'num_rows' => 5], 5],
        'returned as a string' => [['rows_affected' => 0, 'num_rows' => '4'], 4],
        'returned, none affected known' => [['num_rows' => 5], 5],
        'negative affected' => [['rows_affected' => -1, 'num_rows' => 6], 6],
        'unknown' => [['rows_affected' => 0], null],
        'nothing' => [[], null],
    ];

    foreach ($cases as $label => [$properties, $expected]) {
        $GLOBALS['wpdb'] = (object) ($properties + ['queries' => [['a', 0.1, '', 0.0, [QueryTracer::KEY => ['frames' => []]]]], 'last_error' => '']);

        (new QueryTracer)->completePrevious();

        expect($GLOBALS['wpdb']->queries[0][4][QueryTracer::KEY]['rows'])->toBe($expected, $label);
    }
});
