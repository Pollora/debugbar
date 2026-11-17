<?php

declare(strict_types=1);

use DebugBar\DataFormatter\DataFormatter;
use Pollora\Debugbar\Collectors\WpQueriesCollector;
use Pollora\Debugbar\Recording\QueryTracer;
use Pollora\Debugbar\Support\Components;

/**
 * WordPress keeps each query as [sql, seconds, caller, start, data] once
 * SAVEQUERIES is on; the collector turns them into the SQL widget's shape.
 */
beforeEach(function (): void {
    $GLOBALS['wpdb'] = (object) ['queries' => [
        ['SELECT * FROM wp_options', 0.002, "require('wp-blog-header.php'), wp_load_alloptions", 1.0],
        ['SELECT * FROM wp_posts WHERE ID = 1', 0.08, "require('wp-blog-header.php'), wp, WP->main, WP_Query->get_posts", 1.1],
        ['SELECT * FROM wp_options', 0.001, '', 1.2],
    ]];
    $GLOBALS['wp_the_query'] = (object) ['request' => 'SELECT * FROM wp_posts WHERE ID = 1'];
});

afterEach(function (): void {
    unset($GLOBALS['wpdb'], $GLOBALS['wp_the_query']);
});

function queriesFrom(WpQueriesCollector $collector): array
{
    return $collector->setDataFormatter(new DataFormatter)->collect();
}

it('lists every query with its time and the wpdb connection', function (): void {
    $data = queriesFrom(new WpQueriesCollector);

    expect($data['nb_statements'])->toBe(3)
        ->and($data['statements'][0]['sql'])->toBe('SELECT * FROM wp_options')
        ->and($data['statements'][0]['connection'])->toBe('wpdb')
        ->and($data['accumulated_duration'])->toEqualWithDelta(0.083, 0.0001);
});

it('shows the caller innermost first, and marks the main query', function (): void {
    $statement = queriesFrom(new WpQueriesCollector)['statements'][1];

    expect($statement['backtrace'][0])->toBe('WP_Query->get_posts')
        ->and($statement['filename'])->toBe('main query · WP_Query->get_posts');
});

it('flags queries at or over the slow threshold', function (): void {
    $statements = queriesFrom(new WpQueriesCollector(slowThreshold: 50))['statements'];

    expect(array_column($statements, 'slow'))->toBe([false, true, false]);
});

it('drops backtraces past the soft limit and queries past the hard one', function (): void {
    $data = queriesFrom(new WpQueriesCollector(softLimit: 1, hardLimit: 2));

    expect($data['nb_statements'])->toBe(2)
        ->and($data['nb_excluded_statements'])->toBe(1)
        ->and($data['statements'][1]['backtrace'])->toBe([]);
});

it('shows nothing when WordPress kept no queries', function (): void {
    unset($GLOBALS['wpdb']);

    expect(queriesFrom(new WpQueriesCollector)['nb_statements'])->toBe(0);
});

it('uses the tracer\'s backtrace, error and rows, and groups queries by component', function (): void {
    $GLOBALS['wpdb']->queries = [[
        'SELECT * FROM wp_nope', 0.001, 'ignored caller string', 1.0,
        [QueryTracer::KEY => [
            'frames' => [
                ['file' => '/site/public/cms/wp-includes/option.php', 'line' => 12, 'call' => 'get_option'],
                ['file' => '/site/public/content/plugins/acme/acme.php', 'line' => 30, 'call' => 'acme_boot'],
            ],
            'error' => "Table 'wp_nope' doesn't exist",
            'rows' => 0,
        ]],
    ]];

    $components = new Components([
        '/site/public/content/plugins/' => ['kind' => 'plugin', 'name' => null],
        '/site/public/cms/' => ['kind' => 'core', 'name' => ''],
    ]);

    $data = queriesFrom(new WpQueriesCollector(components: $components));
    $statement = $data['statements'][0];

    expect($statement['connection'])->toBe('wpdb · plugin: acme')
        ->and($statement['is_success'])->toBeFalse()
        ->and($statement['error_message'])->toBe("Table 'wp_nope' doesn't exist")
        ->and($statement['row_count'])->toBe(0)
        ->and($statement['backtrace'][1])->toBe('acme_boot — /site/public/content/plugins/acme/acme.php:30')
        ->and($data['nb_failed_statements'])->toBe(1);
});

it('gives each statement every field the SQL widget reads', function (): void {
    $GLOBALS['wpdb']->queries = [['  SELECT 1  ', 0.25, 'a, b, c', 1.0]];

    $statement = queriesFrom(new WpQueriesCollector)['statements'][0];

    expect($statement['sql'])->toBe('SELECT 1')
        ->and($statement['type'])->toBe('query')
        ->and($statement['params'])->toEqual((object) [])
        ->and($statement['duration'])->toBe(0.25)
        ->and($statement['duration_str'])->toBe((new DataFormatter)->formatDuration(0.25))
        ->and($statement['memory_str'])->toBe('')
        ->and($statement['row_count'])->toBeNull()
        ->and($statement['is_success'])->toBeTrue()
        ->and($statement['error_code'])->toBeNull()
        ->and($statement['error_message'])->toBeNull()
        ->and($statement['backtrace'])->toBe(['c', 'b', 'a'])
        ->and($statement['filename'])->toBe('c')
        ->and($statement['xdebug_link'])->toBeNull()
        ->and($statement['slow'])->toBeFalse()
        ->and($statement['connection'])->toBe('wpdb');
});

it('totals the queries and places each one on the timeline by its share of the total', function (): void {
    $GLOBALS['wpdb']->queries = [['a', 0.1, '', 1.0], ['b', 0.3, '', 1.0], ['c', 0.6, '', 1.0]];

    $data = queriesFrom(new WpQueriesCollector);
    $formatter = new DataFormatter;

    expect($data['accumulated_duration'])->toEqualWithDelta(1.0, 0.0001)
        ->and($data['accumulated_duration_str'])->toBe($formatter->formatDuration($data['accumulated_duration']))
        ->and($data['memory_usage_str'])->toBe('')
        ->and($data['nb_failed_statements'])->toBe(0)
        ->and($data['nb_excluded_statements'])->toBe(0)
        ->and(array_column($data['statements'], 'start_percent'))->toBe([0.0, 10.0, 40.0])
        ->and(array_column($data['statements'], 'width_percent'))->toBe([10.0, 30.0, 60.0]);
});

it('gives a timeline of zero width when no query took any time', function (): void {
    $GLOBALS['wpdb']->queries = [['a', 0, '', 1.0], ['b', 0, '', 1.0]];

    $statements = queriesFrom(new WpQueriesCollector)['statements'];

    expect(array_column($statements, 'start_percent'))->toBe([0.0, 0.0])
        ->and(array_column($statements, 'width_percent'))->toBe([0.0, 0.0]);
});

it('flags a query exactly at the threshold and never when there is none', function (): void {
    $GLOBALS['wpdb']->queries = [['a', 0.05, '', 1.0], ['b', 0.0499, '', 1.0]];

    expect(array_column(queriesFrom(new WpQueriesCollector(slowThreshold: 50.0))['statements'], 'slow'))->toBe([true, false])
        ->and(array_column(queriesFrom(new WpQueriesCollector)['statements'], 'slow'))->toBe([false, false]);
});

it('keeps the backtrace of the queries before the soft limit and only those, and the hard limit is exclusive', function (): void {
    $GLOBALS['wpdb']->queries = [['a', 0.1, 'x', 1.0], ['b', 0.1, 'y', 1.0], ['c', 0.1, 'z', 1.0]];

    $data = queriesFrom(new WpQueriesCollector(softLimit: 2, hardLimit: 3));

    expect(array_column($data['statements'], 'backtrace'))->toBe([['x'], ['y'], []])
        ->and(array_column($data['statements'], 'filename'))->toBe(['x', 'y', ''])
        ->and($data['nb_statements'])->toBe(3);
});

it('reads queries stored under any keys, in order', function (): void {
    $GLOBALS['wpdb']->queries = [5 => ['a', 0.1, 'x', 1.0], 9 => ['b', 0.1, 'y', 1.0]];

    expect(array_column(queriesFrom(new WpQueriesCollector(hardLimit: 1))['statements'], 'sql'))->toBe(['a']);
});

it('marks the main query only when it is the statement, ignoring surrounding spaces', function (): void {
    $GLOBALS['wp_the_query'] = (object) ['request' => "  SELECT 1 \n"];
    $GLOBALS['wpdb']->queries = [['SELECT 1', 0.1, 'x', 1.0], ['SELECT 2', 0.1, 'y', 1.0]];

    expect(array_column(queriesFrom(new WpQueriesCollector)['statements'], 'filename'))->toBe(['main query · x', 'y']);

    $GLOBALS['wp_the_query'] = (object) ['request' => 42];

    expect(array_column(queriesFrom(new WpQueriesCollector)['statements'], 'filename'))->toBe(['x', 'y']);
});

it('completes the last traced query, with the error WordPress reported, before it reads them', function (): void {
    $GLOBALS['wpdb'] = (object) [
        'last_error' => 'Deadlock',
        'queries' => [['a', 0.1, '', 1.0, [QueryTracer::KEY => ['frames' => [['file' => '/x', 'line' => 1, 'call' => 'c']]]]]],
    ];

    $data = queriesFrom(new WpQueriesCollector(tracer: new QueryTracer));

    expect($data['statements'][0]['error_message'])->toBe('Deadlock')
        ->and($data['statements'][0]['is_success'])->toBeFalse()
        ->and($data['nb_failed_statements'])->toBe(1);
});

it('takes the component a traced query already carries, and a failure-free query counts none', function (): void {
    $GLOBALS['wpdb']->queries = [
        ['a', 0.1, '', 1.0, [QueryTracer::KEY => ['frames' => [['file' => '/x/a.php', 'line' => 3, 'call' => 'run']], 'component' => 'plugin: given', 'rows' => 4]]],
        ['b', 0.1, 'caller', 1.0, [QueryTracer::KEY => ['frames' => []]]],
    ];

    $data = queriesFrom(new WpQueriesCollector);

    expect($data['statements'][0]['connection'])->toBe('wpdb · plugin: given')
        ->and($data['statements'][0]['row_count'])->toBe(4)
        ->and($data['statements'][0]['backtrace'])->toBe(['run — /x/a.php:3'])
        ->and($data['statements'][0]['filename'])->toBe('run')
        ->and($data['statements'][0]['is_success'])->toBeTrue()
        ->and($data['statements'][1]['backtrace'])->toBe(['caller'])
        ->and($data['statements'][1]['connection'])->toBe('wpdb')
        ->and($data['nb_failed_statements'])->toBe(0);
});

it('counts every failed query, each once', function (): void {
    $failing = static fn (string $sql): array => [$sql, 0.1, '', 1.0, [QueryTracer::KEY => ['frames' => [['file' => '/x', 'line' => 1, 'call' => 'c']], 'error' => 'boom']]];
    $GLOBALS['wpdb']->queries = [$failing('a'), ['b', 0.1, '', 1.0], $failing('c')];

    expect(queriesFrom(new WpQueriesCollector)['nb_failed_statements'])->toBe(2);
});

it('reads a query as WordPress keeps it, whatever the types, and a query missing its fields as an empty one', function (): void {
    $GLOBALS['wpdb']->queries = [[5, '0.5', 123, 1.0, ['other' => 1]], [], ['SELECT 1']];

    $statements = queriesFrom(new WpQueriesCollector)['statements'];

    expect($statements[0]['sql'])->toBe('5')
        ->and($statements[0]['duration'])->toBe(0.5)
        ->and($statements[0]['backtrace'])->toBe(['123'])
        ->and($statements[0]['row_count'])->toBeNull()
        ->and($statements[0]['connection'])->toBe('wpdb')
        ->and($statements[1]['sql'])->toBe('')
        ->and($statements[1]['duration'])->toBe(0.0)
        ->and($statements[1]['backtrace'])->toBe([])
        ->and($statements[1]['filename'])->toBe('')
        ->and($statements[2]['duration'])->toBe(0.0);
});

it('reads a caller string without empty parts or stray spaces, innermost first and as a list', function (): void {
    $GLOBALS['wpdb']->queries = [['a', 0.1, ' x ,, y ,z,', 1.0]];

    expect(queriesFrom(new WpQueriesCollector)['statements'][0]['backtrace'])->toBe(['z', 'y', 'x']);
});

it('rounds the position and width of each query on the timeline to three decimals', function (): void {
    $GLOBALS['wpdb']->queries = [['a', 1, '', 1.0], ['b', 2, '', 1.0]];

    $statements = queriesFrom(new WpQueriesCollector)['statements'];

    expect(array_column($statements, 'start_percent'))->toBe([0.0, 33.333])
        ->and(array_column($statements, 'width_percent'))->toBe([33.333, 66.667]);
});

it('keeps the query list in step with what was excluded, counting from the first', function (): void {
    $GLOBALS['wpdb']->queries = [['a', 0.1, '', 1.0], ['b', 0.1, '', 1.0], ['c', 0.1, '', 1.0], ['d', 0.1, '', 1.0]];

    $data = queriesFrom(new WpQueriesCollector(hardLimit: 1));

    expect($data['nb_statements'])->toBe(1)
        ->and($data['nb_excluded_statements'])->toBe(3)
        ->and($data['accumulated_duration'])->toEqualWithDelta(0.1, 0.0001);
});
