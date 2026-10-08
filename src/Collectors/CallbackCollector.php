<?php

declare(strict_types=1);

namespace Pollora\Debugbar\Collectors;

use Pollora\Debugbar\Collector;
use Pollora\Debugbar\Widget;

/**
 * A tab built from a closure, for code that registers through the
 * `pollora/debugbar/register` action rather than a class of its own.
 */
final class CallbackCollector extends Collector
{
    /**
     * @param  \Closure(): array<array-key, mixed>  $data
     * @param  array<string, string>  $columns
     */
    public function __construct(
        private readonly string $name,
        private readonly string $title,
        private readonly string $origin,
        private readonly \Closure $data,
        private readonly Widget $widget = Widget::Variables,
        private readonly array $columns = [],
        private readonly string $icon = 'puzzle',
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function origin(): string
    {
        return $this->origin;
    }

    public function widget(): Widget
    {
        return $this->widget;
    }

    public function icon(): string
    {
        return $this->icon;
    }

    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function data(): array
    {
        return ($this->data)();
    }
}
