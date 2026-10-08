<?php

declare(strict_types=1);

namespace Pollora\Debugbar\Collectors;

use Pollora\Debugbar\Collector;
use Pollora\Debugbar\Origin;
use Pollora\Debugbar\Recording\RequestRecorder;
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
     * A callback as a reader recognises it: `Class::method`, a function name or `Closure`.
     */
    public static function describe(mixed $callback): string
    {
        return match (true) {
            is_string($callback) => $callback,
            is_array($callback) && isset($callback[0], $callback[1]) => (is_object($callback[0]) ? $callback[0]::class : (string) $callback[0]).'::'.(string) $callback[1],
            $callback instanceof \Closure => 'Closure',
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
                    $byHook[(string) $hook][] = sprintf('%s @%d', self::describe($callback), (int) ($registration['priority'] ?? 10));
                }
            }
        }

        return $byHook;
    }
}
