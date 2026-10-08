<?php

declare(strict_types=1);

namespace Pollora\Debugbar\Recording;

use Pollora\BlockBinding\Domain\Events\BindingResolved;

/**
 * Times each block render and keeps the block bindings resolved.
 *
 * `pre_render_block` opens a render and `render_block` closes it; a block a
 * plugin answers in `pre_render_block` never reaches `render_block`, so it is
 * not opened either.
 */
final class BlockRecorder
{
    private bool $installed = false;

    /**
     * @var list<array{name: string, start: float}>
     */
    private array $open = [];

    /**
     * @var array<string, array{count: int, milliseconds: float, maxDepth: int}>
     */
    private array $blocks = [];

    /**
     * @var list<BindingResolved>
     */
    private array $bindings = [];

    public function install(): void
    {
        if ($this->installed || ! function_exists('add_filter')) {
            return;
        }

        $this->installed = true;

        add_filter('pre_render_block', function (mixed $pre, mixed $parsedBlock): mixed {
            if ($pre === null && is_array($parsedBlock)) {
                $this->open[] = ['name' => (string) ($parsedBlock['blockName'] ?? 'freeform'), 'start' => microtime(true)];
            }

            return $pre;
        }, PHP_INT_MAX, 2);

        add_filter('render_block', function (mixed $content): mixed {
            $this->close();

            return $content;
        }, PHP_INT_MIN, 1);
    }

    public function recordBinding(BindingResolved $binding): void
    {
        $this->bindings[] = $binding;
    }

    /**
     * @return array<string, array{count: int, milliseconds: float, maxDepth: int}>
     */
    public function blocks(): array
    {
        return $this->blocks;
    }

    /**
     * @return list<BindingResolved>
     */
    public function bindings(): array
    {
        return $this->bindings;
    }

    public function reset(): void
    {
        $this->open = [];
        $this->blocks = [];
        $this->bindings = [];
    }

    private function close(): void
    {
        $render = array_pop($this->open);

        if ($render === null) {
            return;
        }

        $block = $this->blocks[$render['name']] ?? ['count' => 0, 'milliseconds' => 0.0, 'maxDepth' => 0];
        $block['count']++;
        $block['milliseconds'] += (microtime(true) - $render['start']) * 1000;
        $block['maxDepth'] = max($block['maxDepth'], count($this->open));

        $this->blocks[$render['name']] = $block;
    }
}
