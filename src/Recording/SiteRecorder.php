<?php

declare(strict_types=1);

namespace Pollora\Debugbar\Recording;

/**
 * Records the site switches of a multisite request: `switch_to_blog()` and
 * `restore_current_blog()`, with who made them.
 *
 * Installed on every request, since a switch costs one listener call and
 * single sites never fire `switch_blog`.
 */
final class SiteRecorder
{
    private bool $installed = false;

    /**
     * @var list<array{from: int, to: int, context: string, frames: list<array{file: string}>}>
     */
    private array $switches = [];

    public function install(): void
    {
        if ($this->installed || ! function_exists('add_action')) {
            return;
        }

        $this->installed = true;

        add_action('switch_blog', function (mixed $to, mixed $from, mixed $context = 'switch'): void {
            $this->switches[] = [
                'from' => (int) $from,
                'to' => (int) $to,
                'context' => is_string($context) ? $context : 'switch',
                'frames' => array_values(array_filter(
                    array_map(static fn (array $frame): array => ['file' => $frame['file'] ?? ''], debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15)),
                    static fn (array $frame): bool => $frame['file'] !== '',
                )),
            ];
        }, PHP_INT_MAX, 3);
    }

    /**
     * @return list<array{from: int, to: int, context: string, frames: list<array{file: string}>}>
     */
    public function switches(): array
    {
        return $this->switches;
    }

    public function reset(): void
    {
        $this->switches = [];
    }
}
