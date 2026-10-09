<?php

declare(strict_types=1);

namespace Pollora\Debugbar\Collectors;

use Pollora\Debugbar\Collector;
use Pollora\Debugbar\Origin;
use Pollora\Debugbar\Recording\HookTimer;
use Pollora\Debugbar\Support\Components;
use Pollora\Debugbar\Widget;

/**
 * The slowest hook callbacks of the request, by their own time: what they
 * spent outside the hooks they fired in turn. Query Monitor has no such view.
 */
final class WpHookTimingsCollector extends Collector
{
    public function __construct(
        private readonly HookTimer $timer,
        private readonly Components $components,
        private readonly int $limit = 200,
    ) {}

    public function getName(): string
    {
        return 'wp_hook_timings';
    }

    public function title(): string
    {
        return 'WP Hook timings';
    }

    public function origin(): string
    {
        return Origin::WORDPRESS;
    }

    public function icon(): string
    {
        return 'clock';
    }

    public function widget(): Widget
    {
        return Widget::Table;
    }

    public function position(): int
    {
        return 35;
    }

    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'self' => 'Own time',
            'total' => 'Total',
            'calls' => 'Calls',
            'hook' => 'Hook',
            'component' => 'From',
        ];
    }

    /**
     * @return array<string, array{self: string, total: string, calls: int, hook: string, component: string}>
     */
    protected function data(): array
    {
        $timings = $this->timer->timings();

        usort($timings, static fn (array $a, array $b): int => $b['self'] <=> $a['self']);

        $rows = [];

        foreach (array_slice($timings, 0, max(1, $this->limit)) as $timing) {
            $key = WpHooksCollector::describe($timing['callback']);

            // The same callback can run on several hooks
            while (isset($rows[$key])) {
                $key .= ' ';
            }

            $rows[$key] = [
                'self' => self::milliseconds($timing['self']),
                'total' => self::milliseconds($timing['total']),
                'calls' => $timing['calls'],
                'hook' => sprintf('%s @%d', $timing['hook'], $timing['priority']),
                'component' => $timing['file'] !== '' ? $this->components->of($timing['file']) : 'unknown',
            ];
        }

        return $rows;
    }

    private static function milliseconds(int $nanoseconds): string
    {
        return number_format($nanoseconds / 1_000_000, 2).' ms';
    }
}
