<?php

declare(strict_types=1);

namespace Pollora\Debugbar;

/**
 * Who a tab's data comes from, and where the tab sits in the bar.
 *
 * Laravel's own tabs keep Debugbar's order (0). Pollora's tab comes next, then
 * WordPress's, then everyone else's — php-debugbar sorts tabs by this number
 * and has no grouping of its own.
 */
final class Origin
{
    /** What only the framework knows: the route that answered, discovery, modules. */
    public const string POLLORA = 'pollora';

    /** What WordPress core, plugins and themes do: queries, hooks, the main query. */
    public const string WORDPRESS = 'wordpress';

    /**
     * The order band a tab of this origin sits in.
     */
    public static function band(string $origin): int
    {
        return match ($origin) {
            self::POLLORA => 1000,
            self::WORDPRESS => 2000,
            default => 3000,
        };
    }

    /**
     * Whether a collector name is kept for this package's own tabs.
     *
     * A third party picking `wp_queries` would replace a built-in tab — or
     * collide with it, since Debugbar refuses a name twice.
     */
    public static function isReservedName(string $name): bool
    {
        return str_starts_with($name, 'wp_') || str_starts_with($name, 'pollora');
    }

    /**
     * How the origin reads in the bar.
     */
    public static function label(string $origin): string
    {
        return match ($origin) {
            self::POLLORA => 'Pollora',
            self::WORDPRESS => 'WordPress',
            default => $origin,
        };
    }
}
