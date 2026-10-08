<?php

declare(strict_types=1);

namespace Pollora\Debugbar;

use Pollora\Debugbar\Collectors\CallbackCollector;

/**
 * What the `pollora/debugbar/register` action hands to WordPress code.
 *
 * A plugin adds a tab or a section with closures, without depending on this
 * package: when it is not installed, nothing fires the action.
 *
 *     add_action('pollora/debugbar/register', function ($bar): void {
 *         $bar->table('acme_cart', 'Acme cart', fn (): array => acme_cart_rows(), origin: 'acme-shop');
 *         $bar->section('wp_request', 'Acme', fn (): array => ['Cart' => acme_cart_id()]);
 *     });
 */
final class Registry
{
    /**
     * @var list<Collector>
     */
    private array $collectors = [];

    /**
     * @var list<array{tab: string, title: string, values: \Closure(): array<string, mixed>}>
     */
    private array $sections = [];

    /**
     * A tab of name => value pairs.
     *
     * @param  \Closure(): array<string, mixed>  $values
     */
    public function variables(string $name, string $title, \Closure $values, string $origin, string $icon = 'box'): static
    {
        $this->collectors[] = new CallbackCollector($this->checked($name), $title, $origin, $values, icon: $icon);

        return $this;
    }

    /**
     * A tab of rows sharing the same columns.
     *
     * @param  \Closure(): array<array-key, array<string, mixed>>  $rows  Row key => [column => value]
     * @param  array<string, string>  $columns  Column key => label; empty to show every field
     */
    public function table(string $name, string $title, \Closure $rows, string $origin, array $columns = [], string $icon = 'table'): static
    {
        $this->collectors[] = new CallbackCollector($this->checked($name), $title, $origin, $rows, Widget::Table, $columns, $icon);

        return $this;
    }

    /**
     * A section appended to an existing tab (`pollora`, `wp_request`, or another package's).
     *
     * @param  \Closure(): array<string, mixed>  $values
     */
    public function section(string $tab, string $title, \Closure $values): static
    {
        $this->sections[] = ['tab' => $tab, 'title' => $title, 'values' => $values];

        return $this;
    }

    /**
     * @return list<Collector>
     */
    public function collectors(): array
    {
        return $this->collectors;
    }

    /**
     * @return list<array{tab: string, title: string, values: \Closure(): array<string, mixed>}>
     */
    public function sections(): array
    {
        return $this->sections;
    }

    private function checked(string $name): string
    {
        if (Origin::isReservedName($name)) {
            throw new \InvalidArgumentException(sprintf(
                'The debug bar tab name "%s" is reserved: names starting with "wp_" or "pollora" belong to pollora/debugbar. Prefix it with your own name instead.',
                $name,
            ));
        }

        return $name;
    }
}
