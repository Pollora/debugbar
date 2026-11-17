<?php

declare(strict_types=1);

use DebugBar\DataFormatter\DataFormatter;
use Pollora\BlockBinding\Domain\Events\BindingResolved;
use Pollora\Debugbar\Collectors\WpBlocksCollector;
use Pollora\Debugbar\Origin;
use Pollora\Debugbar\Recording\BlockRecorder;

require_once __DIR__.'/../../Fixtures/wordpress.php';

afterEach(function (): void {
    WP_Block_Type_Registry::get_instance()->registered_block_types = [];
});

function blockRows(BlockRecorder $recorder): array
{
    return (new WpBlocksCollector($recorder))->setDataFormatter(new DataFormatter)->collect()['data']['data'];
}

function renderedBlocks(string ...$names): BlockRecorder
{
    $recorder = new BlockRecorder;
    $rendered = new ReflectionProperty($recorder, 'blocks');
    $rendered->setValue($recorder, array_fill_keys($names, ['count' => 1, 'milliseconds' => 2.0, 'maxDepth' => 0]));

    return $recorder;
}

function registerBlock(string $name, mixed $render): void
{
    $type = new WP_Block_Type($name);
    $type->render_callback = $render;
    WP_Block_Type_Registry::get_instance()->registered_block_types[$name] = $type;
}

it('tells core blocks, Blade blocks of Pollora, other dynamic blocks and static ones apart', function (): void {
    registerBlock('acme/blade', Closure::bind(static fn (): string => '', null, Origin::class));
    registerBlock('acme/dynamic', static fn (): string => '');
    registerBlock('acme/callable', 'strtoupper');
    registerBlock('acme/static', null);

    $rows = blockRows(renderedBlocks('core/paragraph', 'acme/blade', 'acme/dynamic', 'acme/callable', 'acme/static', 'acme/unregistered'));

    expect(array_column($rows, 'kind'))->toBe(['core', 'Blade (Pollora)', 'dynamic', 'dynamic', 'static', 'static']);
});

it('shows how deep a block was nested and how long it took', function (): void {
    $recorder = renderedBlocks('core/group');
    (new ReflectionProperty($recorder, 'blocks'))->setValue($recorder, ['core/group' => ['count' => 4, 'milliseconds' => 12.0, 'maxDepth' => 3]]);

    expect(blockRows($recorder)['core/group'])->toMatchArray(['count' => 4, 'detail' => 'nested up to 3 deep']);
});

it('merges the bindings of a source and lists every post they resolved', function (): void {
    $recorder = new BlockRecorder;
    $recorder->recordBinding(new BindingResolved('acme/event', 'seats', 'content', 7, 1.0, false, true));
    $recorder->recordBinding(new BindingResolved('acme/event', 'seats', 'content', 9, 1.0, false, true));
    $recorder->recordBinding(new BindingResolved('acme/event', '', 'url', null, 1.0, false, true));

    $rows = blockRows($recorder);

    expect($rows['binding acme/event.seats → content']['detail'])->toBe('post 7, 9')
        ->and($rows['binding acme/event → url']['detail'])->toBe('');
});

it('shows each block with its count, its time as the formatter writes it, its kind and its depth', function (): void {
    $recorder = new BlockRecorder;
    (new ReflectionProperty($recorder, 'blocks'))->setValue($recorder, [
        'core/group' => ['count' => 4, 'milliseconds' => 12.0, 'maxDepth' => 3],
        'core/flat' => ['count' => 1, 'milliseconds' => 1500.0, 'maxDepth' => 0],
    ]);

    $rows = blockRows($recorder);
    $formatter = new DataFormatter;

    expect($rows)->toBe([
        'core/group' => ['count' => 4, 'time' => $formatter->formatDuration(0.012), 'kind' => 'core', 'detail' => 'nested up to 3 deep'],
        'core/flat' => ['count' => 1, 'time' => $formatter->formatDuration(1.5), 'kind' => 'core', 'detail' => ''],
    ]);
});

it('sums the bindings of one source, and tells the posts, the cache hits and the values missing', function (): void {
    $recorder = new BlockRecorder;
    $recorder->recordBinding(new BindingResolved('acme/event', 'seats', 'content', 7, 250.0, true, true));
    $recorder->recordBinding(new BindingResolved('acme/event', 'seats', 'content', 7, 250.0, false, false));
    $recorder->recordBinding(new BindingResolved('acme/event', 'seats', 'content', 9, 500.0, true, false));

    $row = blockRows($recorder)['binding acme/event.seats → content'];

    expect($row)->toBe([
        'count' => 3,
        'time' => (new DataFormatter)->formatDuration(1.0),
        'kind' => 'binding',
        'detail' => 'post 7, 9, 2 from cache, 2 without value',
    ]);
});

it('leaves out of a binding what it has nothing to say about', function (): void {
    $recorder = new BlockRecorder;
    $recorder->recordBinding(new BindingResolved('acme/a', 'f', 'content', 3, 1.0, false, true));
    $recorder->recordBinding(new BindingResolved('acme/b', 'f', 'content', null, 1.0, true, true));
    $recorder->recordBinding(new BindingResolved('acme/c', 'f', 'content', null, 1.0, false, false));

    $rows = blockRows($recorder);

    expect($rows['binding acme/a.f → content']['detail'])->toBe('post 3')
        ->and($rows['binding acme/b.f → content']['detail'])->toBe('1 from cache')
        ->and($rows['binding acme/c.f → content']['detail'])->toBe('1 without value');
});

it('calls a block rendered by a closure of another class, or of none, dynamic', function (): void {
    registerBlock('acme/outside', Closure::bind(static fn (): string => '', null, WpBlocksOutsider::class));
    registerBlock('acme/scopeless', static fn (): string => '');

    $rows = blockRows(renderedBlocks('acme/outside', 'acme/scopeless'));

    expect(array_column($rows, 'kind'))->toBe(['dynamic', 'dynamic']);
});

final class WpBlocksOutsider {}
