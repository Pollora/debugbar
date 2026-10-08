<?php

declare(strict_types=1);

namespace Pollora\Debugbar\Collectors;

use Pollora\Debugbar\Collector;
use Pollora\Debugbar\Origin;
use Pollora\Debugbar\Recording\HttpRecorder;
use Pollora\Debugbar\Support\Components;
use Pollora\Debugbar\Widget;

/**
 * The HTTP calls WordPress made during the request (`wp_remote_*`).
 */
final class WpHttpCollector extends Collector
{
    public function __construct(
        private readonly HttpRecorder $recorder,
        private readonly Components $components,
    ) {}

    public function getName(): string
    {
        return 'wp_http';
    }

    public function title(): string
    {
        return 'WP HTTP';
    }

    public function origin(): string
    {
        return Origin::WORDPRESS;
    }

    public function icon(): string
    {
        return 'external-link';
    }

    public function widget(): Widget
    {
        return Widget::Table;
    }

    public function position(): int
    {
        return 40;
    }

    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return ['result' => 'Result', 'time' => 'Time', 'transport' => 'Transport', 'component' => 'From'];
    }

    /**
     * @return array<string, array{result: string, time: string, transport: string, component: string}>
     */
    protected function data(): array
    {
        $rows = [];

        foreach ($this->recorder->calls() as $index => $call) {
            $result = match (true) {
                $call['error'] !== null => 'error: '.$call['error'],
                $call['end'] === null => 'no response recorded',
                $call['shortCircuited'] => ($call['status'] ?? 'answered').' (answered by pre_http_request)',
                default => (string) ($call['status'] ?? '—'),
            };

            $rows[sprintf('%d. %s %s', $index + 1, $call['method'], $call['url'])] = [
                'result' => $result,
                'time' => $call['end'] !== null ? $this->getDataFormatter()->formatDuration($call['end'] - $call['start']) : '—',
                'transport' => $call['transport'] ?? '—',
                'component' => $this->components->ofTrace($call['frames']),
            ];
        }

        return $rows;
    }
}
