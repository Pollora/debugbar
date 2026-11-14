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
