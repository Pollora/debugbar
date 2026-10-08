<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use DebugBar\DataFormatter\DataFormatter;
use Pollora\Debugbar\Collectors\WpRequestCollector;
use Pollora\Debugbar\Recording\RequestRecorder;

beforeEach(function (): void {
    $GLOBALS['wp'] = (object) [
        'request' => 'hello-world',
        'matched_rule' => '([^/]+)(?:/([0-9]+))?/?$',
        'matched_query' => 'name=hello-world&page=',
        'query_vars' => ['name' => 'hello-world'],
    ];
    $GLOBALS['wp_the_query'] = (object) [
        'query_vars' => ['name' => 'hello-world', 'paged' => 0, 'post_type' => ''],
        'post_count' => 1,
        'found_posts' => 1,
        'max_num_pages' => 0,
    ];

    Functions\when('get_queried_object')->justReturn(null);
});

afterEach(function (): void {
    unset($GLOBALS['wp'], $GLOBALS['wp_the_query'], $GLOBALS['wp_query']);
});

function requestData(?RequestRecorder $recorder = null): array
{
    return (new WpRequestCollector($recorder ?? new RequestRecorder))->setDataFormatter(new DataFormatter)->collect()['data'];
}

it('shows how WordPress parsed the request', function (): void {
    $data = requestData();

    expect($data['Request'])->toBe('hello-world')
        ->and($data['Matched query'])->toBe('name=hello-world&page=')
        ->and($data['Main query results'])->toBe('1 of 1 found, 0 page(s)');
});

it('lists only the conditionals that are true', function (): void {
    $GLOBALS['wp_query'] = new stdClass;

    foreach (WpRequestCollector::CONDITIONALS as $conditional) {
        Functions\when($conditional)->justReturn(in_array($conditional, ['is_single', 'is_singular'], true));
    }

    expect(requestData()['Conditionals'])->toBe('is_single(), is_singular()');
});

it('leaves conditionals out before the main query exists, where they would complain', function (): void {
    Functions\expect('is_single')->never();

    expect(requestData())->not->toHaveKey('Conditionals');
});
