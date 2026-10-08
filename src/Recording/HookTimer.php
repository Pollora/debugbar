<?php

declare(strict_types=1);

namespace Pollora\Debugbar\Recording;

/**
 * Times each hook callback: how often it ran, for how long, and how much of
 * that was its own work rather than the hooks it fired in turn.
 *
 * `WP_Hook` is final, so its loop cannot be timed from outside. Instead, when
 * a hook is about to run (the `all` hook), each of its callbacks not timed
 * yet is replaced in `$wp_filter` by a TimedCallback. The entry keeps its key,
 * which is all `remove_action()`, `has_filter()` and a re-add look at.
 *
 * Callbacks taking parameters by reference are left as they are: a variadic
 * wrapper would hand them copies. So are this package's own callbacks.
 */
final class HookTimer
{
    private bool $installed = false;

    /**
     * Each timed callback, by hook, priority and WordPress's callback id.
     *
     * @var array<string, array{hook: string, priority: int, callback: mixed, file: string, calls: int, total: int, self: int, max: int}>
     */
    private array $timings = [];

    /**
     * Callback ids already looked at, and whether they can be timed.
     *
     * @var array<string, array{file: string}|false>
     */
    private array $decisions = [];

    /**
     * Start time and time spent in nested callbacks, innermost last, in nanoseconds.
     *
     * @var list<array{0: int, 1: int}>
     */
    private array $stack = [];

    public function install(): void
    {
        if ($this->installed || ! function_exists('add_action')) {
            return;
        }

        $this->installed = true;

        add_action('all', function (mixed $hook): void {
            if (is_string($hook)) {
                $this->wrap($hook);
            }
        }, PHP_INT_MIN, 1);
    }

    public function isInstalled(): bool
    {
        return $this->installed;
    }

    /**
     * Put a timer around every callback of a hook not timed yet.
     */
    public function wrap(string $hook): void
    {
        global $wp_filter;

        $wpHook = is_array($wp_filter) ? ($wp_filter[$hook] ?? null) : null;

        if (! is_object($wpHook) || ! isset($wpHook->callbacks) || ! is_array($wpHook->callbacks)) {
            return;
        }

        foreach ($wpHook->callbacks as $priority => $callbacks) {
            foreach ((array) $callbacks as $id => $entry) {
                $callback = $entry['function'] ?? null;

                if ($callback === null || $callback instanceof TimedCallback) {
                    continue;
                }

                $decision = $this->decisions[(string) $id] ??= $this->decide($callback);

                if ($decision === false) {
                    continue;
                }

                $key = $hook.'|'.$priority.'|'.$id;

                $this->timings[$key] ??= [
                    'hook' => $hook,
                    'priority' => (int) $priority,
                    'callback' => $callback,
                    'file' => $decision['file'],
                    'calls' => 0,
                    'total' => 0,
                    'self' => 0,
                    'max' => 0,
                ];

                $wpHook->callbacks[$priority][$id]['function'] = new TimedCallback($callback, $this, $key);
            }
        }
    }

    /**
     * A callback starts.
     */
    public function enter(): void
    {
        $this->stack[] = [hrtime(true), 0];
    }

    /**
     * The callback that started last ends.
     */
    public function leave(string $key): void
    {
        $frame = array_pop($this->stack);

        if ($frame === null) {
            return;
        }

        $elapsed = hrtime(true) - $frame[0];
        $last = array_key_last($this->stack);

        if ($last !== null) {
            $this->stack[$last][1] += $elapsed;
        }

        if (! isset($this->timings[$key])) {
            return;
        }

        $timing = &$this->timings[$key];
        $timing['calls']++;
        $timing['total'] += $elapsed;
        $timing['self'] += $elapsed - $frame[1];
        $timing['max'] = max($timing['max'], $elapsed);
    }

    /**
     * The callbacks that ran, times in nanoseconds.
     *
     * @return list<array{hook: string, priority: int, callback: mixed, file: string, calls: int, total: int, self: int, max: int}>
     */
    public function timings(): array
    {
        return array_values(array_filter($this->timings, static fn (array $timing): bool => $timing['calls'] > 0));
    }

    public function reset(): void
    {
        $this->timings = [];
        $this->stack = [];
    }

    /**
     * Whether a callback can be timed, and the file it was written in.
     *
     * @return array{file: string}|false
     */
    private function decide(mixed $callback): array|false
    {
        try {
            $reflection = match (true) {
                $callback instanceof \Closure => new \ReflectionFunction($callback),
                is_string($callback) && str_contains($callback, '::') => new \ReflectionMethod($callback),
                is_string($callback) => new \ReflectionFunction($callback),
                is_array($callback) && isset($callback[0], $callback[1]) && (is_object($callback[0]) || is_string($callback[0])) && is_string($callback[1]) => new \ReflectionMethod($callback[0], $callback[1]),
                is_object($callback) && method_exists($callback, '__invoke') => new \ReflectionMethod($callback, '__invoke'),
                default => null,
            };
        } catch (\ReflectionException) {
            // Not callable yet, or answered by __call(): leave it alone
            return false;
        }

        if ($reflection === null) {
            return false;
        }

        foreach ($reflection->getParameters() as $parameter) {
            if ($parameter->isPassedByReference()) {
                return false;
            }
        }

        $class = $reflection instanceof \ReflectionMethod ? $reflection->getDeclaringClass()->getName() : $reflection->getClosureScopeClass()?->getName();

        if ($class !== null && str_starts_with($class, 'Pollora\\Debugbar\\')) {
            return false;
        }

        return ['file' => (string) $reflection->getFileName()];
    }
}
