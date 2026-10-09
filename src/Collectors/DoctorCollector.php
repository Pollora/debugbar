<?php

declare(strict_types=1);

namespace Pollora\Debugbar\Collectors;

use Pollora\Debugbar\Collector;
use Pollora\Debugbar\Origin;

/**
 * The Doctor tab: a button that runs `pollora:doctor`'s web checks on demand.
 *
 * Collects nothing but the address to call, so the page pays nothing for it.
 * Its widget is inlined in the page, since Debugbar's assets request only
 * knows the collectors registered while it runs.
 */
final class DoctorCollector extends Collector
{
    public function __construct(
        private readonly string $url,
    ) {}

    public function getName(): string
    {
        return 'pollora_doctor';
    }

    public function title(): string
    {
        return 'Doctor';
    }

    public function origin(): string
    {
        return Origin::POLLORA;
    }

    public function icon(): string
    {
        return 'bug';
    }

    public function position(): int
    {
        return 1;
    }

    /**
     * @return array{data: array{url: string}, count: null}
     */
    public function collect(): array
    {
        return ['data' => $this->data(), 'count' => null];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getWidgets(): array
    {
        $widgets = parent::getWidgets();
        $widgets['pollora_doctor']['widget'] = 'PhpDebugBar.Widgets.PolloraDoctorWidget';

        return $widgets;
    }

    /**
     * @return array{inline_js: array<string, string>}
     */
    public function getAssets(): array
    {
        return ['inline_js' => ['pollora-doctor-widget' => (string) file_get_contents(__DIR__.'/../../resources/doctor-widget.js')]];
    }

    /**
     * @return array{url: string}
     */
    protected function data(): array
    {
        return ['url' => $this->url];
    }
}
