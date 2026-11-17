<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use DebugBar\DataFormatter\DataFormatter;
use Pollora\Debugbar\Collectors\WpRequestCollector;
use Pollora\Debugbar\Recording\RequestRecorder;
use Pollora\Debugbar\Recording\SiteRecorder;
use Pollora\Debugbar\Support\Components;

require_once __DIR__.'/../../Fixtures/wordpress.php';

beforeEach(function (): void {
    Functions\when('get_queried_object')->justReturn(null);
});

afterEach(function (): void {
    unset($GLOBALS['wp'], $GLOBALS['wp_the_query'], $GLOBALS['wp_query'], $GLOBALS['pagenow'], $GLOBALS['hook_suffix'], $GLOBALS['current_site'], $GLOBALS['_wp_switched_stack']);
});

function detailsOf(?RequestRecorder $recorder = null, ?SiteRecorder $sites = null, ?Components $components = null): array
{
    $collector = (new WpRequestCollector($recorder ?? new RequestRecorder, $sites, $components))->setDataFormatter(new DataFormatter);

    // The raw values, before the widget formats them
    return (new ReflectionMethod($collector, 'data'))->invoke($collector);
}

function recorderThatSaw(?string $template, array $hierarchy = []): RequestRecorder
{
    $captured = new ArrayObject;
    Filters\expectAdded('template_include')->zeroOrMoreTimes()->whenHappen(function (callable $callback) use ($captured): void {
        $captured['template_include'] = $callback;
    });
    Filters\expectAdded('single_template_hierarchy')->zeroOrMoreTimes()->whenHappen(function (callable $callback) use ($captured): void {
        $captured['single'] = $callback;
    });

    $recorder = new RequestRecorder;
    $recorder->install();

    if ($template !== null) {
        $captured['template_include']($template);
    }

    if ($hierarchy !== []) {
        $captured['single']($hierarchy);
    }

    return $recorder;
}

it('shows nothing of the request when WordPress has not parsed one', function (): void {
    $data = detailsOf();

    expect(array_keys($data))->toBe(['Queried object', 'Template', 'Template hierarchy'])
        ->and($data['Queried object'])->toBeNull()
        ->and($data['Template'])->toBeNull()
        ->and($data['Template hierarchy'])->toBeNull();
});

it('shows the rule, query and variables WordPress matched, exactly', function (): void {
    $GLOBALS['wp'] = (object) [
        'request' => 'a/b',
        'matched_rule' => 'a/(.+)$',
        'matched_query' => 'name=b',
        'query_vars' => ['name' => 'b', 'page' => 2],
    ];

    $data = detailsOf();

    expect(array_keys($data))->toBe(['Request', 'Matched rule', 'Matched query', 'Query vars', 'Queried object', 'Template', 'Template hierarchy'])
        ->and($data['Request'])->toBe('a/b')
        ->and($data['Matched rule'])->toBe('a/(.+)$')
        ->and($data['Matched query'])->toBe('name=b')
        ->and($data['Query vars'])->toBe(['name' => 'b', 'page' => 2]);
});

it('shows a request with nothing known as empty values', function (): void {
    $GLOBALS['wp'] = new stdClass;

    $data = detailsOf();

    expect($data['Request'])->toBe('')
        ->and($data['Matched rule'])->toBeNull()
        ->and($data['Matched query'])->toBeNull()
        ->and($data['Query vars'])->toBe([]);
});

it('keeps in the main query only the variables that are set, and counts its results', function (): void {
    $GLOBALS['wp_the_query'] = (object) [
        'query_vars' => ['a' => '', 'b' => [], 'c' => null, 'd' => false, 'e' => 0, 'f' => 'x', 'g' => '0', 'h' => [1], 'i' => 5, 'j' => true],
        'post_count' => 3,
        'found_posts' => 7,
        'max_num_pages' => 2,
    ];

    $data = detailsOf();

    expect($data['Main query'])->toBe(['f' => 'x', 'g' => '0', 'h' => [1], 'i' => 5, 'j' => true])
        ->and($data['Main query results'])->toBe('3 of 7 found, 2 page(s)');
});

it('counts a main query without results as zeros', function (): void {
    $GLOBALS['wp_the_query'] = (object) ['query_vars' => []];

    expect(detailsOf()['Main query results'])->toBe('0 of 0 found, 0 page(s)')
        ->and(detailsOf()['Main query'])->toBe([]);
});

it('has no main query section without query variables', function (): void {
    $GLOBALS['wp_the_query'] = (object) ['post_count' => 1];

    expect(detailsOf())->not->toHaveKey('Main query')
        ->and(detailsOf())->not->toHaveKey('Main query results');
});

it('shows an empty conditionals line when none is true', function (): void {
    $GLOBALS['wp_query'] = new stdClass;

    foreach (WpRequestCollector::CONDITIONALS as $conditional) {
        Functions\when($conditional)->justReturn(false);
    }

    expect(detailsOf()['Conditionals'])->toBe('');
});

it('names the queried object by its kind', function (): void {
    $post = new WP_Post;
    $post->ID = 12;
    $post->post_type = 'page';
    $post->post_title = 'About';
    $term = new WP_Term;
    $term->term_id = 4;
    $term->taxonomy = 'genre';
    $term->name = 'Jazz';
    $user = new WP_User;
    $user->ID = 9;
    $user->display_name = 'Ada';
    $type = new WP_Post_Type;
    $type->name = 'book';

    $objects = [
        'post #12 (page) About' => $post,
        'term #4 (genre) Jazz' => $term,
        'user #9 Ada' => $user,
        'post type book' => $type,
    ];

    foreach ($objects as $expected => $object) {
        Functions\when('get_queried_object')->justReturn($object);

        expect(detailsOf()['Queried object'])->toBe($expected);
    }

    Functions\when('get_queried_object')->justReturn(new stdClass);

    expect(detailsOf()['Queried object'])->toBeNull();
});

it('shows the template WordPress settled on and the hierarchy it built', function (): void {
    $recorder = recorderThatSaw('/elsewhere/single.php', ['single-post.php', 'single.php']);

    $data = detailsOf($recorder);

    expect($data['Template'])->toBe('/elsewhere/single.php')
        ->and($data['Template hierarchy'])->toBe(['single' => ['single-post.php', 'single.php']]);
});

it('shows no template line without a template', function (): void {
    $data = detailsOf(recorderThatSaw(null));

    expect($data['Template'])->toBeNull()
        ->and($data['Template hierarchy'])->toBeNull();
});

it('shows nothing of wp-admin outside it, or before WordPress parsed the URL', function (): void {
    Functions\when('get_current_screen')->justReturn(new WP_Screen);
    Functions\when('is_admin')->justReturn(false);
    $GLOBALS['pagenow'] = 'index.php';

    expect(detailsOf())->not->toHaveKey('Admin page');

    Functions\when('is_admin')->justReturn(true);
    unset($GLOBALS['pagenow']);

    expect(detailsOf())->not->toHaveKey('Admin page');
});

it('shows the admin screen with only the facts it has, and the block editor when it is one', function (): void {
    $GLOBALS['pagenow'] = 'post.php';
    unset($GLOBALS['hook_suffix']);
    $screen = new WP_Screen;
    $screen->taxonomy = '';
    $screen->post_type = 'page';
    $screen->blockEditor = true;
    Functions\when('is_admin')->justReturn(true);
    Functions\when('get_current_screen')->justReturn($screen);

    $data = detailsOf();

    expect($data['Admin page'])->toBe('post.php')
        ->and($data)->not->toHaveKey('Hook suffix')
        ->and($data['Screen'])->toBe(['id' => 'edit-post', 'base' => 'edit', 'post type' => 'page', 'block editor' => 'yes']);

    $GLOBALS['hook_suffix'] = 'post-new.php';
    $screen->taxonomy = 'genre';
    $screen->blockEditor = false;

    $data = detailsOf();

    expect($data['Hook suffix'])->toBe('post-new.php')
        ->and($data['Screen'])->toBe(['id' => 'edit-post', 'base' => 'edit', 'post type' => 'page', 'taxonomy' => 'genre']);
});

it('shows no hook suffix when it is empty, and no screen before there is one', function (): void {
    $GLOBALS['pagenow'] = 'index.php';
    $GLOBALS['hook_suffix'] = '';
    Functions\when('is_admin')->justReturn(true);
    Functions\when('get_current_screen')->justReturn(null);

    $data = detailsOf();

    expect($data['Admin page'])->toBe('index.php')
        ->and($data)->not->toHaveKey('Hook suffix')
        ->and($data)->not->toHaveKey('Screen');
});

/**
 * @param  list<array{0: int, 1: int, 2: string}>  $switches  [to, from, context]
 */
function siteRecorderWith(array $switches): SiteRecorder
{
    $callback = null;
    Actions\expectAdded('switch_blog')->once()->whenHappen(function (callable $added) use (&$callback): void {
        $callback = $added;
    });
    $sites = new SiteRecorder;
    $sites->install();

    foreach ($switches as [$to, $from, $context]) {
        $callback($to, $from, $context);
    }

    return $sites;
}

function multisiteWith(int $current, bool $switched = false, array $stack = []): void
{
    $GLOBALS['current_site'] = (object) ['id' => 1];
    $GLOBALS['_wp_switched_stack'] = $stack;
    Functions\when('is_multisite')->justReturn(true);
    Functions\when('get_current_blog_id')->justReturn($current);
    Functions\when('is_main_site')->alias(fn (int $site): bool => $site === 1);
    Functions\when('get_current_network_id')->justReturn(7);
    Functions\when('ms_is_switched')->justReturn($switched);
}

it('shows nothing of a network on a single site', function (): void {
    $GLOBALS['current_site'] = (object) ['id' => 1];
    Functions\when('is_multisite')->justReturn(false);

    expect(detailsOf())->not->toHaveKey('Site');

    unset($GLOBALS['current_site']);
    Functions\when('is_multisite')->justReturn(true);

    expect(detailsOf())->not->toHaveKey('Site');
});

it('shows the site and the network of a multisite that never switched', function (): void {
    multisiteWith(2);

    $data = detailsOf(sites: siteRecorderWith([]));

    expect($data['Site'])->toBe('#2')
        ->and($data['Network'])->toBe('#7')
        ->and($data['Site switches'])->toBe('none')
        ->and($data)->not->toHaveKey('Still switched');
});

it('shows the main site as such', function (): void {
    multisiteWith(1);

    expect(detailsOf()['Site'])->toBe('#1 (main site)')
        ->and(detailsOf()['Site switches'])->toBe('none');
});

it('names the first site switched away from while switched, and the switch it was never restored from', function (): void {
    multisiteWith(5, true, [2, 3]);

    $data = detailsOf(sites: siteRecorderWith([[5, 2, 'switch']]));

    expect($data['Site'])->toBe('#2')
        ->and($data['Still switched'])->toBe('yes, to #5: restore_current_blog() was not called for every switch_to_blog()')
        ->and($data['Site switches'])->toBe(['switch #2 → #5']);
});

it('falls back to the current site when switched with no usable stack', function (): void {
    multisiteWith(5, true, []);

    expect(detailsOf()['Site'])->toBe('#5');

    $GLOBALS['_wp_switched_stack'] = 'broken';

    expect(detailsOf()['Site'])->toBe('#5');
});

it('shows each switch with the component that made it', function (): void {
    multisiteWith(1);
    $sites = siteRecorderWith([[2, 1, 'switch'], [1, 2, 'restore']]);

    $withComponents = detailsOf(sites: $sites, components: new Components([__DIR__.'/' => ['kind' => 'plugin', 'name' => 'acme']]));
    $without = detailsOf(sites: $sites);

    expect($withComponents['Site switches'])->toHaveCount(2)
        ->and($withComponents['Site switches'][0])->toMatch('/^switch #1 → #2 \(plugin: acme\)$/')
        ->and($withComponents['Site switches'][1])->toMatch('/^restore #2 → #1 \(plugin: acme\)$/')
        ->and($without['Site switches'])->toBe(['switch #1 → #2', 'restore #2 → #1']);
});
