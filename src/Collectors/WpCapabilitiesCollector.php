<?php

declare(strict_types=1);

namespace Pollora\Debugbar\Collectors;

use Pollora\Debugbar\Collector;
use Pollora\Debugbar\Origin;
use Pollora\Debugbar\Recording\CapabilityRecorder;
use Pollora\Debugbar\Support\Components;
use Pollora\Debugbar\Widget;

/**
 * The capability checks of the request, once per distinct check, with how
 * many times each was made. Laravel's Gate tab keeps its own checks.
 */
final class WpCapabilitiesCollector extends Collector
{
    public function __construct(
        private readonly CapabilityRecorder $recorder,
        private readonly Components $components,
    ) {}

    public function getName(): string
    {
        return 'wp_capabilities';
    }

    public function title(): string
    {
        return 'WP Capabilities';
    }

    public function origin(): string
    {
        return Origin::WORDPRESS;
    }

    public function icon(): string
    {
        return 'circle-check';
    }

    public function widget(): Widget
    {
        return Widget::Table;
    }

    public function position(): int
    {
        return 60;
    }

    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return ['result' => 'Result', 'user' => 'User', 'count' => 'Checks', 'component' => 'From'];
    }

    /**
     * @return array<string, array{result: string, user: string, count: int, component: string}>
     */
    protected function data(): array
    {
        $rows = [];

        foreach ($this->recorder->checks() as $check) {
            $label = $check['capability'].($check['arguments'] !== '' ? "({$check['arguments']})" : '');
            $key = $label;

            // The same capability can be granted to one user and refused to another
            while (isset($rows[$key])) {
                $key .= ' ';
            }

            $rows[$key] = [
                'result' => $check['granted'] ? 'granted' : 'refused',
                'user' => $check['user'] > 0 ? '#'.$check['user'] : 'visitor',
                'count' => $check['count'],
                'component' => $check['frames'] !== [] ? $this->components->ofTrace($check['frames']) : '—',
            ];
        }

        return $rows;
    }
}
