<?php

declare(strict_types=1);

namespace Pollora\Debugbar\Collectors;

use DebugBar\DataCollector\DataCollectorInterface;
use DebugBar\DataCollector\TimeDataCollector;
use Pollora\Debugbar\Recording\RequestRecorder;

/**
 * Puts WordPress's phases on Debugbar's own timeline.
 *
 * Not a tab: at collection time it hands the times the recorder kept to the
 * time collector, which php-debugbar collects last precisely so that others
 * can still add to it.
 */
final class WpTimelineCollector implements DataCollectorInterface
{
    public function __construct(
        private readonly RequestRecorder $recorder,
        private readonly TimeDataCollector $time,
    ) {}

    public function getName(): string
    {
        return 'wp_timeline';
    }

    /**
     * @return array{phases: int}
     */
    public function collect(): array
    {
        $phases = $this->recorder->phases();
        $bootedAt = $this->recorder->bootedAt();

        if ($bootedAt !== null && isset($phases['wp_loaded'])) {
            $this->time->addMeasure('WordPress loading', $bootedAt, $phases['wp_loaded']['end'], [], 'wordpress', 'WordPress');
        }

        foreach ($phases as $action => $phase) {
            $this->time->addMeasure("{$action} callbacks", $phase['start'], $phase['end'], [], 'wordpress', 'WordPress');
        }

        return ['phases' => count($phases)];
    }
}
