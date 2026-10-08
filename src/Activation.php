<?php

declare(strict_types=1);

namespace Pollora\Debugbar;

use Fruitcake\LaravelDebugbar\LaravelDebugbar;
use Illuminate\Contracts\Foundation\Application;

/**
 * Whether Pollora's tabs run for this request.
 *
 * Decided while providers register, because WordPress loads while they boot
 * and what has to be in place before it (`SAVEQUERIES`, the recorder) cannot
 * wait. So it repeats Laravel Debugbar's own check without resolving the bar,
 * which may not be registered yet.
 */
final class Activation
{
    public static function shouldRun(Application $app): bool
    {
        if (! class_exists(LaravelDebugbar::class) || $app->runningInConsole()) {
            return false;
        }

        $config = $app->make('config');

        if (! $config->get('debugbar-pollora.enabled', true)) {
            return false;
        }

        if (! LaravelDebugbar::canBeEnabled()) {
            return false;
        }

        // Debugbar merges its config in its own register(), which may come
        // after this one: read its environment default when the key is not
        // there yet.
        $enabled = $config->has('debugbar.enabled')
            ? value($config->get('debugbar.enabled'))
            : env('DEBUGBAR_ENABLED');

        return (bool) ($enabled ?? $config->get('app.debug'));
    }
}
