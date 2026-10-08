<?php

declare(strict_types=1);

namespace Pollora\Debugbar\Collectors;

use Pollora\Debugbar\Collector;
use Pollora\Debugbar\Origin;
use Pollora\Debugbar\Recording\RequestRecorder;
use Pollora\Debugbar\Recording\TimedCallback;
use Pollora\Debugbar\Widget;

/**
 * The hooks that ran, how many callbacks each had, and which of those Pollora
 * registered.
 *
 * Actions are counted by WordPress itself (`$wp_actions`), so they cost
 * nothing. Filters are only counted when the `all` listener is on, since it
 * runs on every `apply_filters()` call.
 */
final class WpHooksCollector extends Collector
{
    /**
     * @param  list<object>  $polloraHooks  Pollora's Action and Filter services
     */
    public function __construct(
        private readonly RequestRecorder $recorder,
        private readonly array $polloraHooks = [],
    ) {}

    public function getName(): string
    {
        return 'wp_hooks';
    }

    public function title(): string
    {
        return 'WP Hooks';
    }

    public function origin(): string
    {
        return Origin::WORDPRESS;
    }

    public function icon(): string
    {
        return 'link';
    }

    public function widget(): Widget
    {
        return Widget::Table;
    }

    public function position(): int
    {
        return 30;
    }

    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'calls' => 'Ran',
            'callbacks' => 'Callbacks',
            'pollora' => 'Registered by Pollora',
        ];
    }

    /**
     * A callback as a reader recognises it: `Class::method`, a function name,
     * or where a closure was written.
     */
    public static function describe(mixed $callback): string
    {
        return match (true) {
            $callback instanceof TimedCallback => self::describe($callback->callback),
            is_string($callback) => $callback,
            is_array($callback) && isset($callback[0], $callback[1]) => (is_object($callback[0]) ? $callback[0]::class : (string) $callback[0]).'::'.(string) $callback[1],
            $callback instanceof \Closure => self::describeClosure($callback),
            is_object($callback) => $callback::class,
            default => 'unknown',
        };
    }

    /**
     * @return array<string, array{calls: int, callbacks: int, pollora: string}>
     */
    protected function data(): array
    {
        global $wp_actions, $wp_filter;

        $calls = $this->recorder->hookCalls() ?? (is_array($wp_actions) ? $wp_actions : []);
        $pollora = $this->polloraCallbacks();
        $rows = [];

        foreach ($calls as $hook => $count) {
            $hook = (string) $hook;
            $registered = 0;

            if (is_array($wp_filter) && isset($wp_filter[$hook]) && is_object($wp_filter[$hook]) && isset($wp_filter[$hook]->callbacks)) {
                foreach ((array) $wp_filter[$hook]->callbacks as $callbacks) {
                    $registered += count((array) $callbacks);
                }
            }

            $rows[$hook] = [
                'calls' => (int) $count,
                'callbacks' => $registered,
                'pollora' => implode(', ', $pollora[$hook] ?? []),
            ];
        }

        return $rows;
    }

    /**
     * `$this->boot(...)` reads as `Class::boot`; a real closure as the class
     * it was written in and its file and line, since "Closure" alone says
     * nothing when most of Pollora's callbacks are closures.
     */
    private static function describeClosure(\Closure $closure): string
    {
        $reflection = new \ReflectionFunction($closure);
        $scopeClass = $reflection->getClosureScopeClass();

        // A file included from a method (a mu-plugin WordPress loads from
        // Pollora's Bootstrap) gives its closures that class's scope: name
        // the class only when the closure is written in it
        $scope = $scopeClass !== null && $scopeClass->getFileName() === $reflection->getFileName() ? $scopeClass->getName() : null;

        if (! str_contains($reflection->getName(), '{closure')) {
            return $scope !== null ? "{$scope}::{$reflection->getName()}" : $reflection->getName();
        }

        $where = basename((string) $reflection->getFileName()).':'.$reflection->getStartLine();

        return $scope !== null ? "closure in {$scope} ({$where})" : "closure ({$where})";
    }

    private static function priority(int $priority): string
    {
        return match ($priority) {
            PHP_INT_MAX => 'last',
            PHP_INT_MIN => 'first',
            default => (string) $priority,
        };
    }

    /**
     * Pollora's registrations by hook, as `Class::method` names.
     *
     * Read from the hook services' own records: `$wp_filter` holds the same
     * callbacks but cannot say who registered them.
     *
     * @return array<string, list<string>>
     */
    private function polloraCallbacks(): array
    {
        $byHook = [];

        foreach ($this->polloraHooks as $service) {
            if (! method_exists($service, 'all')) {
                continue;
            }

            foreach ((array) $service->all() as $hook => $registrations) {
                foreach ((array) $registrations as $registration) {
                    $callback = $registration['handler'] ?? $registration['callback'] ?? null;
                    $byHook[(string) $hook][] = sprintf('%s @%s', self::describe($callback), self::priority((int) ($registration['priority'] ?? 10)));
                }
            }
        }

        return $byHook;
    }
}
