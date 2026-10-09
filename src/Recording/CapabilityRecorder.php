<?php

declare(strict_types=1);

namespace Pollora\Debugbar\Recording;

/**
 * Records `current_user_can()` and `user_can()` checks, aggregated.
 *
 * A page checks the same few capabilities hundreds of times; identical
 * checks (user, capability, arguments, result) are counted once, which keeps
 * the cost low enough to stay on — Query Monitor turns its panel off by
 * default because it keeps a backtrace per check. Backtraces are opt-in here.
 */
final class CapabilityRecorder
{
    private bool $installed = false;

    /**
     * @var array<string, array{user: int, capability: string, arguments: string, granted: bool, count: int, frames: list<array{file: string}>}>
     */
    private array $checks = [];

    public function __construct(
        private readonly bool $backtrace = false,
    ) {}

    public function install(): void
    {
        if ($this->installed || ! function_exists('add_filter')) {
            return;
        }

        $this->installed = true;

        add_filter('user_has_cap', function (mixed $allcaps, mixed $caps, mixed $args): mixed {
            if (is_array($allcaps) && is_array($caps) && is_array($args)) {
                $this->record($allcaps, $caps, $args);
            }

            return $allcaps;
        }, PHP_INT_MAX, 3);
    }

    /**
     * @return list<array{user: int, capability: string, arguments: string, granted: bool, count: int, frames: list<array{file: string}>}>
     */
    public function checks(): array
    {
        return array_values($this->checks);
    }

    public function reset(): void
    {
        $this->checks = [];
    }

    /**
     * @param  array<string, mixed>  $allcaps  What the user has
     * @param  array<int, string>  $caps  The primitive capabilities the check maps to
     * @param  array<int, mixed>  $args  [requested capability, user id, ...extra arguments]
     */
    private function record(array $allcaps, array $caps, array $args): void
    {
        $capability = (string) ($args[0] ?? '');
        $user = (int) ($args[1] ?? 0);
        $arguments = implode(', ', array_map(
            static fn (mixed $argument): string => is_scalar($argument) ? (string) $argument : get_debug_type($argument),
            array_slice($args, 2),
        ));

        // Granted when every primitive capability it maps to is held, as WP_User::has_cap() decides
        $granted = $caps !== [];

        foreach ($caps as $cap) {
            if (empty($allcaps[$cap])) {
                $granted = false;

                break;
            }
        }

        $key = $user.'|'.$capability.'|'.$arguments.'|'.($granted ? 1 : 0);

        if (isset($this->checks[$key])) {
            $this->checks[$key]['count']++;

            return;
        }

        $this->checks[$key] = [
            'user' => $user,
            'capability' => $capability,
            'arguments' => $arguments,
            'granted' => $granted,
            'count' => 1,
            'frames' => $this->backtrace ? array_values(array_filter(
                array_map(static fn (array $frame): array => ['file' => $frame['file'] ?? ''], debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15)),
                static fn (array $frame): bool => $frame['file'] !== '',
            )) : [],
        ];
    }
}
