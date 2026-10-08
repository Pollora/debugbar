<?php

declare(strict_types=1);

use DebugBar\DataFormatter\DataFormatter;
use Pollora\Debugbar\Collectors\WpQueriesCollector;

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
