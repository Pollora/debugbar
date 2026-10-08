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
    | wp_request   Rewrite rule, query vars, main query, conditionals, templates
    | wp_queries   $wpdb queries (turns SAVEQUERIES on)
    | wp_hooks     Hooks that ran and the callbacks Pollora registered
    | wp_timeline  WordPress phases on Debugbar's timeline
    | bridges      pollora/debugbar/* and Query Monitor's qm/* actions
    |
    */

    'collectors' => [
        'pollora' => env('DEBUGBAR_POLLORA_COLLECTORS_POLLORA', true),
        'wp_request' => env('DEBUGBAR_POLLORA_COLLECTORS_WP_REQUEST', true),
        'wp_queries' => env('DEBUGBAR_POLLORA_COLLECTORS_WP_QUERIES', true),
        'wp_hooks' => env('DEBUGBAR_POLLORA_COLLECTORS_WP_HOOKS', true),
        'wp_timeline' => env('DEBUGBAR_POLLORA_COLLECTORS_WP_TIMELINE', true),
        'bridges' => env('DEBUGBAR_POLLORA_COLLECTORS_BRIDGES', true),
    ],

    'options' => [

        'wp_queries' => [
            // Milliseconds from which a query is highlighted; null for never
            'slow_threshold' => env('DEBUGBAR_POLLORA_WP_QUERIES_SLOW_THRESHOLD', 50),
            // Past this many queries, no backtrace is kept
            'soft_limit' => (int) env('DEBUGBAR_POLLORA_WP_QUERIES_SOFT_LIMIT', 100),
            // Past this many queries, the rest are left out
            'hard_limit' => (int) env('DEBUGBAR_POLLORA_WP_QUERIES_HARD_LIMIT', 500),
        ],

        'wp_hooks' => [
            // Count filters too: an `all` listener runs on every apply_filters()
            'count_filters' => env('DEBUGBAR_POLLORA_WP_HOOKS_COUNT_FILTERS', false),
        ],

        'bridges' => [
            // Keep Query Monitor's qm/* logging actions working
            'query_monitor' => env('DEBUGBAR_POLLORA_BRIDGES_QUERY_MONITOR', true),
        ],
    ],
];
