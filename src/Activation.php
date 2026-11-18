<?php

declare(strict_types=1);

namespace Pollora\Debugbar;

use Fruitcake\LaravelDebugbar\LaravelDebugbar;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Env;

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
    /**
     * Who sees the bar when `debugbar-pollora.capability` is not set.
     */
    public const string DEFAULT_CAPABILITY = 'manage_options';

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
            : Env::get('DEBUGBAR_ENABLED');

        return (bool) ($enabled ?? $config->get('app.debug'));
    }

    /**
     * The capability the signed-in user needs to see the bar, its data and
     * its endpoints.
     *
     * Falls back to `manage_options` when the setting is missing or blank, so
     * a bad value can only tighten access, never open it.
     */
    public static function capability(Application $app): string
    {
        $capability = $app->make('config')->get('debugbar-pollora.capability', self::DEFAULT_CAPABILITY);

        return is_string($capability) && trim($capability) !== '' ? trim($capability) : self::DEFAULT_CAPABILITY;
    }

    /**
     * Whether the current WordPress user may see the bar, what it collected
     * and the endpoints behind it.
     *
     * The environment check in shouldRun() only says the bar may exist on
     * this server; on a staging site in debug mode it would still show SQL
     * queries, options and paths to every visitor. Only valid once WordPress
     * has set the current user (from `init`); before that, or without
     * WordPress, nobody is allowed, so the check fails closed.
     */
    public static function mayView(Application $app): bool
    {
        if (! function_exists('current_user_can')) {
            return false;
        }

        return (bool) current_user_can(self::capability($app));
    }

    /**
     * Whether the page is loaded inside an iframe: the Site Editor canvas,
     * the Customizer preview, an embed.
     *
     * Browsers say so with `Sec-Fetch-Dest`; `wp_site_preview` covers the
     * Site Editor in a browser that does not send it.
     */
    public static function isFramed(Request $request): bool
    {
        return $request->headers->get('Sec-Fetch-Dest') === 'iframe'
            || $request->query->has('wp_site_preview');
    }
}
