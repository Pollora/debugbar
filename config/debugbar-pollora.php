<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Pollora in the debug bar
    |--------------------------------------------------------------------------
    |
    | Nothing here runs unless Laravel Debugbar itself is enabled for the
    | request (DEBUGBAR_ENABLED, or APP_DEBUG; never in production or testing).
    | This switch turns Pollora's tabs off while keeping the bar.
    |
    */

    'enabled' => env('DEBUGBAR_POLLORA_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Tabs
    |--------------------------------------------------------------------------
    |
    | pollora      What answered the request, discovery, modules, theme, async
    | wp_request   Rewrite rule, query vars, main query, conditionals, templates,
    |              admin screen, multisite site and switches
    | wp_queries   $wpdb queries (turns SAVEQUERIES on)
    | wp_hooks     Hooks that ran and the callbacks Pollora registered; with
    |              options.wp_hooks.timings, a WP Hook timings tab
    | wp_timeline  WordPress phases on Debugbar's timeline
    | wp_http      HTTP calls made through wp_remote_*
    | wp_cache     Object cache, transients set, OPcache
    | wp_capabilities  current_user_can() checks, aggregated
    | wp_blocks    Blocks rendered and block bindings resolved
    | wp_assets    Scripts, styles, script modules and Vite containers
    | wp_languages Locale and translation files
    | doctor       A button that runs pollora:doctor's web checks on demand
    | bridges      pollora/debugbar/* and Query Monitor's qm/* actions
    |
    */

    'collectors' => [
        'pollora' => env('DEBUGBAR_POLLORA_COLLECTORS_POLLORA', true),
        'wp_request' => env('DEBUGBAR_POLLORA_COLLECTORS_WP_REQUEST', true),
        'wp_queries' => env('DEBUGBAR_POLLORA_COLLECTORS_WP_QUERIES', true),
        'wp_hooks' => env('DEBUGBAR_POLLORA_COLLECTORS_WP_HOOKS', true),
        'wp_timeline' => env('DEBUGBAR_POLLORA_COLLECTORS_WP_TIMELINE', true),
        'wp_http' => env('DEBUGBAR_POLLORA_COLLECTORS_WP_HTTP', true),
        'wp_cache' => env('DEBUGBAR_POLLORA_COLLECTORS_WP_CACHE', true),
        'wp_capabilities' => env('DEBUGBAR_POLLORA_COLLECTORS_WP_CAPABILITIES', true),
        'wp_blocks' => env('DEBUGBAR_POLLORA_COLLECTORS_WP_BLOCKS', true),
        'wp_assets' => env('DEBUGBAR_POLLORA_COLLECTORS_WP_ASSETS', true),
        'wp_languages' => env('DEBUGBAR_POLLORA_COLLECTORS_WP_LANGUAGES', true),
        'doctor' => env('DEBUGBAR_POLLORA_COLLECTORS_DOCTOR', true),
        'bridges' => env('DEBUGBAR_POLLORA_COLLECTORS_BRIDGES', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pages inside an iframe
    |--------------------------------------------------------------------------
    |
    | The Site Editor and the Customizer show the front end in an iframe. Those
    | requests are still stored (open them from the bar's request list), but
    | no bar is printed inside the frame unless this is true.
    |
    */

    'iframes' => env('DEBUGBAR_POLLORA_IFRAMES', false),

    /*
    |--------------------------------------------------------------------------
    | wp-admin
    |--------------------------------------------------------------------------
    |
    | WordPress prints admin pages itself, so Laravel Debugbar never shows
    | there on its own. The bar is printed with the admin footer scripts,
    | without Debugbar's tabs about a request Laravel answered.
    |
    */

    'admin' => [
        'enabled' => env('DEBUGBAR_POLLORA_ADMIN', true),
        'hidden_collectors' => ['route', 'views', 'session', 'livewire', 'inertia'],
    ],

    'options' => [

        'wp_queries' => [
            // Milliseconds from which a query is highlighted; null for never
            'slow_threshold' => env('DEBUGBAR_POLLORA_WP_QUERIES_SLOW_THRESHOLD', 50),
            // Past this many queries, no backtrace is kept
            'soft_limit' => (int) env('DEBUGBAR_POLLORA_WP_QUERIES_SOFT_LIMIT', 100),
            // Past this many queries, the rest are left out
            'hard_limit' => (int) env('DEBUGBAR_POLLORA_WP_QUERIES_HARD_LIMIT', 500),
            // Full backtrace, error, rows and component for each query
            'trace' => env('DEBUGBAR_POLLORA_WP_QUERIES_TRACE', true),
        ],

        'wp_capabilities' => [
            // Who asked: a backtrace per distinct check
            'backtrace' => env('DEBUGBAR_POLLORA_WP_CAPABILITIES_BACKTRACE', false),
        ],

        'wp_hooks' => [
            // Count filters too: an `all` listener runs on every apply_filters()
            'count_filters' => env('DEBUGBAR_POLLORA_WP_HOOKS_COUNT_FILTERS', false),
            // Time every callback (WP Hook timings tab); wraps each callback
            // in $wp_filter and adds a timer to every call, so off by default
            'timings' => env('DEBUGBAR_POLLORA_WP_HOOKS_TIMINGS', false),
            // How many callbacks the timings tab lists, slowest first
            'timings_limit' => (int) env('DEBUGBAR_POLLORA_WP_HOOKS_TIMINGS_LIMIT', 200),
        ],

        'bridges' => [
            // Keep Query Monitor's qm/* logging actions working
            'query_monitor' => env('DEBUGBAR_POLLORA_BRIDGES_QUERY_MONITOR', true),
        ],
    ],
];
