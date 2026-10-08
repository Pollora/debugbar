<?php

declare(strict_types=1);

namespace Pollora\Debugbar\Recording;

/**
 * Records the asynchronous actions the request queued, from the
 * `pollora/async/dispatched` action of `pollora/hook` (1.5+).
 */
final class AsyncRecorder
{
    private bool $installed = false;

    /**
     * @var list<array{hook: string, handler: string, driver: string, delay: int}>
     */
    private array $dispatched = [];

    public function install(): void
    {
        if ($this->installed || ! function_exists('add_action')) {
            return;
        }

        $this->installed = true;

        add_action('pollora/async/dispatched', function (mixed $payload, mixed $delay = 0): void {
            if (is_object($payload)) {
                $this->dispatched[] = [
                    'hook' => (string) ($payload->hook ?? ''),
                    'handler' => is_string($payload->handler ?? null) ? $payload->handler : get_debug_type($payload->handler ?? null),
                    'driver' => (string) ($payload->driver ?? ''),
                    'delay' => (int) $delay,
                ];
            }
        }, 10, 2);
    }

    /**
     * @return list<array{hook: string, handler: string, driver: string, delay: int}>
     */
    public function dispatched(): array
    {
        return $this->dispatched;
    }

    public function reset(): void
    {
        $this->dispatched = [];
    }
}
