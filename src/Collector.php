<?php

declare(strict_types=1);

namespace Pollora\Debugbar;

use DebugBar\DataCollector\AssetProvider;
use DebugBar\DataCollector\DataCollector;
use DebugBar\DataCollector\Renderable;
use DebugBar\DataCollector\Resettable;

/**
 * A tab in the debug bar, with its origin, title and widget.
 *
 * Pollora's own tabs extend it, and so can any package: the subclass says what
 * it shows (`data()`), and this class turns it into what php-debugbar's widget
 * for that shape expects, places the tab with the others of its origin, and
 * appends the sections other code added to it.
 */
abstract class Collector extends DataCollector implements AssetProvider, Renderable, Resettable
{
    /**
     * Sections other code appended to this tab, shown after its own values.
     *
     * @var list<array{title: string, values: \Closure(): array<string, mixed>}>
     */
    private array $sections = [];

    /**
     * The tab's title in the bar.
     */
    abstract public function title(): string;

    /**
     * Who the data comes from: Origin::POLLORA, Origin::WORDPRESS, or the
     * owner's own name for a third party (`acme-shop`).
     */
    abstract public function origin(): string;

    /**
     * The widget the data is shown with.
     */
    public function widget(): Widget
    {
        return Widget::Variables;
    }

    /**
     * The tab's icon: one of the icons php-debugbar ships (`box`, `database`,
     * `link`, `leaf`, `search`, `table`, `tags`, `clock`, `bolt`, `flag`…;
     * see its resources/icons.css). Any other name shows an empty square.
     */
    public function icon(): string
    {
        return 'box';
    }

    /**
     * The table's columns, keyed by the row field they show (Table widget only).
     *
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [];
    }

    /**
     * Where the tab sits among the tabs of its origin, from 0.
     */
    public function position(): int
    {
        return 0;
    }

    /**
     * Append a section of name => value pairs to this tab (Variables widget).
     *
     * @param  \Closure(): array<string, mixed>  $values
     */
    public function addSection(string $title, \Closure $values): void
    {
        $this->sections[] = ['title' => $title, 'values' => $values];
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $data = $this->data();

        return match ($this->widget()) {
            Widget::Variables => $this->variables($data),
            Widget::Table => [
                'data' => ['data' => $data, 'key_map' => $this->columns()],
                'count' => count($data),
            ],
            Widget::Queries => $data,
        };
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getWidgets(): array
    {
        $name = $this->getName();
        $queries = $this->widget() === Widget::Queries;

        return [
            $name => [
                'icon' => $this->icon(),
                'title' => $this->title(),
                'tooltip' => Origin::label($this->origin()),
                'widget' => match ($this->widget()) {
                    Widget::Variables => match (true) {
                        $this->isJsonVarDumperUsed() => 'PhpDebugBar.Widgets.JsonVariableListWidget',
                        $this->isHtmlVarDumperUsed() => 'PhpDebugBar.Widgets.HtmlVariableListWidget',
                        default => 'PhpDebugBar.Widgets.VariableListWidget',
                    },
                    Widget::Table => 'PhpDebugBar.Widgets.TableVariableListWidget',
                    Widget::Queries => 'PhpDebugBar.Widgets.SQLQueriesWidget',
                },
                'map' => $queries ? $name : "{$name}.data",
                'default' => '{}',
                'order' => Origin::band($this->origin()) + $this->position(),
            ],
            "{$name}:badge" => [
                'map' => $queries ? "{$name}.nb_statements" : "{$name}.count",
                'default' => 'null',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getAssets(): array
    {
        if ($this->widget() !== Widget::Queries) {
            return [];
        }

        return [
            'css' => 'widgets/sqlqueries/widget.css',
            'js' => 'widgets/sqlqueries/widget.js',
        ];
    }

    public function reset(): void {}

    /**
     * A path relative to the project, so the bar names a file someone can open.
     */
    protected function relativePath(string $path): string
    {
        try {
            $base = function_exists('base_path') ? rtrim(base_path(), '/').'/' : '';
        } catch (\Throwable) {
            // No application to ask: show the path as it is
            return $path;
        }

        return $base !== '' && str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    /**
     * What the tab shows, in the shape its widget takes.
     *
     * Variables: name => value. Table: row key => [column => value].
     * Queries: php-debugbar's SQL statement structure.
     *
     * @return array<array-key, mixed>
     */
    abstract protected function data(): array;

    /**
     * Name => value pairs as the variable list widgets render them.
     *
     * The JSON and HTML widgets write string values as HTML (the HTML one its
     * keys too), so plain strings are escaped; arrays and objects go through
     * the var dumper.
     *
     * @param  array<array-key, mixed>  $values
     * @return array{data: array<string, mixed>, count: int|null}
     */
    private function variables(array $values): array
    {
        foreach ($this->sections as $section) {
            foreach (($section['values'])() as $key => $value) {
                $values["{$section['title']} › {$key}"] = $value;
            }
        }

        $html = $this->isHtmlVarDumperUsed();
        $escape = $html || $this->isJsonVarDumperUsed();
        $formatted = [];

        foreach ($values as $key => $value) {
            $label = $html ? htmlspecialchars((string) $key) : (string) $key;

            $formatted[$label] = match (true) {
                is_string($value), is_int($value), is_float($value) => $escape ? htmlspecialchars((string) $value) : (string) $value,
                is_bool($value) => $value ? 'yes' : 'no',
                $value === null => '—',
                default => $this->getDataFormatter()->formatVar($value),
            };
        }

        return ['data' => $formatted, 'count' => null];
    }
}
