<?php

declare(strict_types=1);

namespace Pollora\Debugbar\Collectors;

use Pollora\Debugbar\Collector;
use Pollora\Debugbar\Origin;
use Pollora\Debugbar\Recording\BlockRecorder;
use Pollora\Debugbar\Widget;

/**
 * The blocks rendered, by type, and the block bindings that gave them values.
 *
 * Blocks Pollora renders with Blade are marked: their view also shows in
 * Laravel's Views tab. A render's time includes its inner blocks.
 */
final class WpBlocksCollector extends Collector
{
    public function __construct(
        private readonly BlockRecorder $recorder,
    ) {}

    public function getName(): string
    {
        return 'wp_blocks';
    }

    public function title(): string
    {
        return 'WP Blocks';
    }

    public function origin(): string
    {
        return Origin::WORDPRESS;
    }

    public function icon(): string
    {
        return 'box';
    }

    public function widget(): Widget
    {
        return Widget::Table;
    }

    public function position(): int
    {
        return 70;
    }

    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return ['count' => 'Count', 'time' => 'Time', 'kind' => 'Kind', 'detail' => 'Detail'];
    }

    /**
     * @return array<string, array{count: int, time: string, kind: string, detail: string}>
     */
    protected function data(): array
    {
        $formatter = $this->getDataFormatter();
        $rows = [];

        foreach ($this->recorder->blocks() as $name => $block) {
            $rows[$name] = [
                'count' => $block['count'],
                'time' => $formatter->formatDuration($block['milliseconds'] / 1000),
                'kind' => $this->kind($name),
                'detail' => $block['maxDepth'] > 0 ? 'nested up to '.$block['maxDepth'].' deep' : '',
            ];
        }

        $bindings = [];

        foreach ($this->recorder->bindings() as $binding) {
            $key = 'binding '.$binding->source.($binding->field !== '' ? '.'.$binding->field : '').' → '.$binding->attribute;
            $row = $bindings[$key] ?? ['count' => 0, 'milliseconds' => 0.0, 'cached' => 0, 'empty' => 0, 'posts' => []];
            $row['count']++;
            $row['milliseconds'] += $binding->milliseconds;
            $row['cached'] += $binding->cached ? 1 : 0;
            $row['empty'] += $binding->hasValue ? 0 : 1;

            if ($binding->postId !== null) {
                $row['posts'][$binding->postId] = true;
            }

            $bindings[$key] = $row;
        }

        foreach ($bindings as $key => $row) {
            $rows[$key] = [
                'count' => $row['count'],
                'time' => $formatter->formatDuration($row['milliseconds'] / 1000),
                'kind' => 'binding',
                'detail' => implode(', ', array_filter([
                    $row['posts'] !== [] ? 'post '.implode(', ', array_keys($row['posts'])) : null,
                    $row['cached'] > 0 ? $row['cached'].' from cache' : null,
                    $row['empty'] > 0 ? $row['empty'].' without value' : null,
                ])),
            ];
        }

        return $rows;
    }

    /**
     * Core, a Pollora Blade block, or another plugin's.
     */
    private function kind(string $name): string
    {
        if (str_starts_with($name, 'core/')) {
            return 'core';
        }

        if (! class_exists(\WP_Block_Type_Registry::class)) {
            return 'block';
        }

        $type = \WP_Block_Type_Registry::get_instance()->get_registered($name);
        $render = $type instanceof \WP_Block_Type ? $type->render_callback : null;

        if ($render instanceof \Closure) {
            $scope = (new \ReflectionFunction($render))->getClosureScopeClass()?->getName() ?? '';

            if (str_starts_with($scope, 'Pollora\\')) {
                return 'Blade (Pollora)';
            }
        }

        return $render !== null ? 'dynamic' : 'static';
    }
}
