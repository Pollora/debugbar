<?php

declare(strict_types=1);

namespace Pollora\Debugbar\Recording;

/**
 * Adds what `SAVEQUERIES` leaves out to each query `$wpdb` keeps: the full
 * backtrace, the error and the number of rows.
 *
 * No `wpdb` subclass: the skeleton's own `db.php` replaces `$wpdb` after
 * anything this package could set, and Query Monitor's drop-in cannot be
 * installed next to it either. Two core filters work whatever the drop-in:
 *
 * - `log_query_custom_data` runs as each query is saved; the backtrace goes
 *   into the query's custom data;
 * - `query` runs at the start of the next query, before WordPress flushes
 *   the previous one's error and row count, which are copied then. The last
 *   query of the request is completed when the bar collects.
 */
final class QueryTracer
{
    /** Key of the data this tracer adds to each query's custom data. */
    public const string KEY = 'pollora_debugbar';

    private const int FRAMES = 25;

    private bool $installed = false;

    public function __construct(
        private readonly int $softLimit = 100,
    ) {}

    public function install(): void
    {
        if ($this->installed || ! function_exists('add_filter')) {
            return;
        }

        $this->installed = true;

        add_filter('log_query_custom_data', fn (mixed $data): mixed => $this->trace($data), PHP_INT_MAX, 1);
        add_filter('query', function (mixed $query): mixed {
            $this->completePrevious();

            return $query;
        }, PHP_INT_MIN, 1);
    }

    /**
     * Copy the last query's error and rows, which no later query will do.
     */
    public function completePrevious(): void
    {
        global $wpdb;

        if (! is_object($wpdb) || ! isset($wpdb->queries) || ! is_array($wpdb->queries) || $wpdb->queries === []) {
            return;
        }

        $index = array_key_last($wpdb->queries);
        $data = $wpdb->queries[$index][4] ?? [];

        if (! is_array($data) || ! isset($data[self::KEY]) || array_key_exists('rows', $data[self::KEY])) {
            return;
        }

        $data[self::KEY]['error'] = is_string($wpdb->last_error ?? null) && $wpdb->last_error !== '' ? $wpdb->last_error : null;
        $data[self::KEY]['rows'] = $this->rows($wpdb);
        $wpdb->queries[$index][4] = $data;
    }

    private function trace(mixed $data): mixed
    {
        global $wpdb;

        $data = is_array($data) ? $data : [];
        $count = is_object($wpdb) && isset($wpdb->queries) && is_array($wpdb->queries) ? count($wpdb->queries) : 0;

        $data[self::KEY] = [
            'frames' => $count < $this->softLimit ? $this->frames() : [],
        ];

        return $data;
    }

    /**
     * The calls that led to the query, innermost first, without `wpdb` and
     * WordPress's hook plumbing.
     *
     * @return list<array{file: string, line: int, call: string}>
     */
    private function frames(): array
    {
        $frames = [];

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::FRAMES + 8) as $frame) {
            $class = $frame['class'] ?? '';

            // The tracer itself, wpdb, and WordPress's hook plumbing say nothing about who asked
            if ($class === self::class || is_a($class, 'wpdb', true) || $class === 'WP_Hook' || ! isset($frame['file'])
                || str_ends_with($frame['file'], '/class-wpdb.php') || str_ends_with($frame['file'], '/wp-includes/plugin.php')) {
                continue;
            }

            $frames[] = [
                'file' => $frame['file'],
                'line' => (int) ($frame['line'] ?? 0),
                'call' => ($class !== '' ? $class.($frame['type'] ?? '::') : '').$frame['function'],
            ];

            if (count($frames) >= self::FRAMES) {
                break;
            }
        }

        return $frames;
    }

    private function rows(object $wpdb): ?int
    {
        $rows = (int) ($wpdb->rows_affected ?? 0);

        return $rows > 0 ? $rows : (isset($wpdb->num_rows) ? (int) $wpdb->num_rows : null);
    }
}
