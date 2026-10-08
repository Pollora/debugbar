<?php

declare(strict_types=1);

use Brain\Monkey\Filters;
use Pollora\Debugbar\Recording\QueryTracer;

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
