<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Pollora\Debugbar\Collector;
use Pollora\Debugbar\Collectors\CallbackCollector;
use Pollora\Debugbar\Collectors\DoctorCollector;
use Pollora\Debugbar\Collectors\PolloraCollector;
use Pollora\Debugbar\Collectors\WpAssetsCollector;
use Pollora\Debugbar\Collectors\WpBlocksCollector;
use Pollora\Debugbar\Collectors\WpCacheCollector;
use Pollora\Debugbar\Collectors\WpCapabilitiesCollector;
use Pollora\Debugbar\Collectors\WpHooksCollector;
use Pollora\Debugbar\Collectors\WpHookTimingsCollector;
use Pollora\Debugbar\Collectors\WpHttpCollector;
use Pollora\Debugbar\Collectors\WpLanguagesCollector;
use Pollora\Debugbar\Collectors\WpQueriesCollector;
use Pollora\Debugbar\Collectors\WpRequestCollector;
use Pollora\Debugbar\Origin;
use Pollora\Debugbar\Recording\BlockRecorder;
use Pollora\Debugbar\Recording\CacheRecorder;
use Pollora\Debugbar\Recording\CapabilityRecorder;
use Pollora\Debugbar\Recording\HookTimer;
use Pollora\Debugbar\Recording\HttpRecorder;
use Pollora\Debugbar\Recording\LanguageRecorder;
use Pollora\Debugbar\Recording\RequestRecorder;
use Pollora\Debugbar\Support\Components;
use Pollora\Debugbar\Widget;

/**
 * @return array<string, Collector>
 */
function shippedCollectors(): array
{
    $components = new Components([]);

    return [
        'pollora' => new PolloraCollector(new Container),
        'wp_request' => new WpRequestCollector(new RequestRecorder),
        'wp_queries' => new WpQueriesCollector,
        'wp_hooks' => new WpHooksCollector(new RequestRecorder),
        'wp_hook_timings' => new WpHookTimingsCollector(new HookTimer, $components),
        'wp_http' => new WpHttpCollector(new HttpRecorder, $components),
        'wp_cache' => new WpCacheCollector(new CacheRecorder, $components),
        'wp_capabilities' => new WpCapabilitiesCollector(new CapabilityRecorder, $components),
        'wp_blocks' => new WpBlocksCollector(new BlockRecorder),
        'wp_assets' => new WpAssetsCollector(new Container),
        'wp_languages' => new WpLanguagesCollector(new LanguageRecorder),
    ];
}

it('names every tab after its collector, once, and gives each a title, an icon and an origin', function (): void {
    foreach (shippedCollectors() as $name => $collector) {
        expect($collector->getName())->toBe($name)
            ->and($collector->title())->not->toBe('')
            ->and($collector->icon())->not->toBe('')
            ->and([Origin::POLLORA, Origin::WORDPRESS])->toContain($collector->origin());
    }
});

it('gives every table tab the columns its rows are keyed by, and the others none', function (): void {
    foreach (shippedCollectors() as $collector) {
        $columns = $collector->columns();

        if ($collector->widget() === Widget::Table) {
            expect($columns)->not->toBeEmpty();
        } else {
            expect($columns)->toBe([]);
        }
    }
});

it('places the tabs of one origin in distinct positions, so the bar order does not depend on registration', function (): void {
    $positions = [];

    foreach (shippedCollectors() as $collector) {
        $positions[$collector->origin()][] = $collector->position();
    }

    foreach ($positions as $ofOrigin) {
        expect(array_unique($ofOrigin))->toHaveCount(count($ofOrigin));
    }
});

it('describes its widget and badge to the bar, in the band of its origin', function (): void {
    foreach (shippedCollectors() as $name => $collector) {
        $widgets = $collector->getWidgets();

        expect($widgets)->toHaveKeys([$name, "{$name}:badge"])
            ->and($widgets[$name]['order'])->toBe(Origin::band($collector->origin()) + $collector->position())
            ->and($widgets[$name]['title'])->toBe($collector->title());
    }
});

it('ships the SQL widget assets only for the tab that shows queries', function (): void {
    foreach (shippedCollectors() as $collector) {
        expect($collector->getAssets() !== [])->toBe($collector->widget() === Widget::Queries);
    }
});

it('takes the widget, icon and columns a third party gave its callback tab', function (): void {
    $collector = new CallbackCollector('acme_shop', 'Shop', 'acme-shop', fn (): array => ['row' => ['qty' => 2]], Widget::Table, ['qty' => 'Quantity'], 'tags');

    expect($collector->getName())->toBe('acme_shop')
        ->and($collector->widget())->toBe(Widget::Table)
        ->and($collector->icon())->toBe('tags')
        ->and($collector->columns())->toBe(['qty' => 'Quantity'])
        ->and($collector->collect()['data']['data'])->toBe(['row' => ['qty' => 2]]);
});

it('gives each shipped tab exactly its title, icon, position and columns', function (): void {
    $expected = [
        'pollora' => ['Pollora', 'leaf', 0, []],
        'wp_request' => ['WP Request', 'search', 10, []],
        'wp_queries' => ['WP Queries', 'database', 20, []],
        'wp_hooks' => ['WP Hooks', 'link', 30, ['calls' => 'Ran', 'callbacks' => 'Callbacks', 'pollora' => 'Registered by Pollora']],
        'wp_hook_timings' => ['WP Hook timings', 'clock', 35, ['self' => 'Own time', 'total' => 'Total', 'calls' => 'Calls', 'hook' => 'Hook', 'component' => 'From']],
        'wp_http' => ['WP HTTP', 'external-link', 40, ['result' => 'Result', 'time' => 'Time', 'transport' => 'Transport', 'component' => 'From']],
        'wp_cache' => ['WP Cache', 'bolt', 50, []],
        'wp_capabilities' => ['WP Capabilities', 'circle-check', 60, ['result' => 'Result', 'user' => 'User', 'count' => 'Checks', 'component' => 'From']],
        'wp_blocks' => ['WP Blocks', 'box', 70, ['count' => 'Count', 'time' => 'Time', 'kind' => 'Kind', 'detail' => 'Detail']],
        'wp_assets' => ['WP Assets', 'file-code', 80, ['printed' => 'Printed', 'source' => 'Source', 'dependencies' => 'Dependencies', 'note' => 'Note']],
        'wp_languages' => ['WP Languages', 'flag', 90, ['file' => 'File', 'found' => 'Found', 'kind' => 'Kind']],
    ];

    foreach (shippedCollectors() as $name => $collector) {
        expect([$collector->title(), $collector->icon(), $collector->position(), $collector->columns()])->toBe($expected[$name], $name);
    }
});

it('gives each shipped tab the widget and origin it belongs to', function (): void {
    $tables = ['wp_hooks', 'wp_hook_timings', 'wp_http', 'wp_capabilities', 'wp_blocks', 'wp_assets', 'wp_languages'];

    foreach (shippedCollectors() as $name => $collector) {
        $widget = match (true) {
            $name === 'wp_queries' => Widget::Queries,
            in_array($name, $tables, true) => Widget::Table,
            default => Widget::Variables,
        };

        expect($collector->widget())->toBe($widget, $name)
            ->and($collector->origin())->toBe($name === 'pollora' ? Origin::POLLORA : Origin::WORDPRESS, $name);
    }
});

it('knows the doctor tab by name, title, icon, position and origin', function (): void {
    $doctor = new DoctorCollector('/doctor');

    expect($doctor->getName())->toBe('pollora_doctor')
        ->and($doctor->title())->toBe('Doctor')
        ->and($doctor->icon())->toBe('bug')
        ->and($doctor->position())->toBe(1)
        ->and($doctor->origin())->toBe(Origin::POLLORA);
});
