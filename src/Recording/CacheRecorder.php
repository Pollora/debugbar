<?php

declare(strict_types=1);

namespace Pollora\Debugbar\Recording;

/**
 * Records the transients set during the request, and who set them.
 */
final class CacheRecorder
{
    private bool $installed = false;

    /**
     * @var list<array{name: string, network: bool, expiration: int, size: int, frames: list<array{file: string}>}>
     */
    private array $transients = [];

    public function install(): void
    {
        if ($this->installed || ! function_exists('add_action')) {
            return;
        }

        $this->installed = true;

        // set_transient / set_site_transient replaced setted_* in WordPress 6.8
        add_action('set_transient', function (mixed $name, mixed $value, mixed $expiration): void {
            $this->record((string) $name, $value, (int) $expiration, false);
        }, PHP_INT_MAX, 3);
        add_action('set_site_transient', function (mixed $name, mixed $value, mixed $expiration): void {
            $this->record((string) $name, $value, (int) $expiration, true);
        }, PHP_INT_MAX, 3);
    }

    /**
     * @return list<array{name: string, network: bool, expiration: int, size: int, frames: list<array{file: string}>}>
     */
    public function transients(): array
    {
        return $this->transients;
    }

    public function reset(): void
    {
        $this->transients = [];
    }

    private function record(string $name, mixed $value, int $expiration, bool $network): void
    {
        $this->transients[] = [
            'name' => $name,
            'network' => $network,
            'expiration' => $expiration,
            'size' => strlen(serialize($value)),
            'frames' => array_values(array_filter(
                array_map(static fn (array $frame): array => ['file' => $frame['file'] ?? ''], debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15)),
                static fn (array $frame): bool => $frame['file'] !== '',
            )),
        ];
    }
}
