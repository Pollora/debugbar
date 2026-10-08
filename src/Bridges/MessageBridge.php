<?php

declare(strict_types=1);

namespace Pollora\Debugbar\Bridges;

use Fruitcake\LaravelDebugbar\LaravelDebugbar;

/**
 * Lets WordPress code log and time into the debug bar through actions.
 *
 * Two vocabularies: this package's own (`pollora/debugbar/message`, `/start`,
 * `/stop`), and Query Monitor's (`qm/debug` … `qm/emergency`, `qm/start`,
 * `qm/stop`), so code written for Query Monitor keeps logging once it is gone.
 * Without this package, nothing listens and the actions cost nothing.
 */
final class MessageBridge
{
    /**
     * Query Monitor's logger levels, one action each (`qm/debug`…).
     */
    public const array LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    /**
     * @param  \Closure(): ?LaravelDebugbar  $debugbar  Resolves the bar once it is collecting, null before
     */
    public function __construct(
        private readonly \Closure $debugbar,
    ) {}

    public function install(bool $queryMonitor = true): void
    {
        if (! function_exists('add_action')) {
            return;
        }

        add_action('pollora/debugbar/message', $this->message(...), 10, 3);
        add_action('pollora/debugbar/start', $this->start(...), 10, 2);
        add_action('pollora/debugbar/stop', $this->stop(...), 10, 1);

        if (! $queryMonitor) {
            return;
        }

        foreach (self::LEVELS as $level) {
            add_action("qm/{$level}", fn (mixed $message, array $context = []) => $this->message($message, $level, $context), 10, 2);
        }

        add_action('qm/start', fn (string $name) => $this->start($name), 10, 1);
        add_action('qm/stop', $this->stop(...), 10, 1);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function message(mixed $message, string $level = 'info', array $context = []): void
    {
        $debugbar = ($this->debugbar)();

        if (! $debugbar instanceof LaravelDebugbar) {
            return;
        }

        if ($message instanceof \Throwable) {
            $debugbar->addThrowable($message);

            return;
        }

        if ($message instanceof \WP_Error) {
            $message = 'WP_Error: '.$message->get_error_message();
        }

        $debugbar->addMessage(is_string($message) ? $this->interpolate($message, $context) : $message, $level, $context);
    }

    public function start(string $name, ?string $label = null): void
    {
        ($this->debugbar)()?->startMeasure($name, $label ?? $name, 'wordpress', 'WordPress');
    }

    public function stop(string $name): void
    {
        ($this->debugbar)()?->stopMeasure($name);
    }

    /**
     * PSR-3 placeholders (`{user}`) filled from the context, as Query Monitor does.
     *
     * @param  array<string, mixed>  $context
     */
    private function interpolate(string $message, array $context): string
    {
        $replacements = [];

        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value instanceof \Stringable) {
                $replacements['{'.$key.'}'] = (string) $value;
            }
        }

        return strtr($message, $replacements);
    }
}
