<?php

declare(strict_types=1);

namespace Pollora\Debugbar\Collectors;

use Pollora\Debugbar\Collector;
use Pollora\Debugbar\Origin;
use Pollora\Debugbar\Recording\QueryTracer;
use Pollora\Debugbar\Support\Components;
use Pollora\Debugbar\Widget;

/**
 * The queries WordPress ran through `$wpdb`, beside Laravel's own.
 *
 * Laravel Debugbar only sees Laravel's PDO connection; WordPress talks to the
 * same database through its own mysqli one. With `SAVEQUERIES` on, `$wpdb`
 * keeps each query with its time and a caller string, read here once, at the
 * end of the request. With the query tracer on, each query also carries its
 * full backtrace, error and row count, and is attributed to a component —
 * shown as the SQL widget's connection, so its filter links group by it.
 */
final class WpQueriesCollector extends Collector
{
    /**
     * @param  float|null  $slowThreshold  Milliseconds from which a query is flagged slow, null for never
     * @param  int  $softLimit  Queries past this one lose their backtrace
     * @param  int  $hardLimit  Queries past this one are left out
     */
    public function __construct(
        private readonly ?float $slowThreshold = null,
        private readonly int $softLimit = 100,
        private readonly int $hardLimit = 500,
        private readonly ?Components $components = null,
        private readonly ?QueryTracer $tracer = null,
    ) {}

    public function getName(): string
    {
        return 'wp_queries';
    }

    public function title(): string
    {
        return 'WP Queries';
    }

    public function origin(): string
    {
        return Origin::WORDPRESS;
    }

    public function icon(): string
    {
        return 'database';
    }

    public function widget(): Widget
    {
        return Widget::Queries;
    }

    public function position(): int
    {
        return 20;
    }

    /**
     * @return array<string, mixed>
     */
    protected function data(): array
    {
        global $wpdb, $wp_the_query;

        $queries = is_object($wpdb) && isset($wpdb->queries) && is_array($wpdb->queries) ? $wpdb->queries : [];
        $mainQuery = is_object($wp_the_query) && isset($wp_the_query->request) && is_string($wp_the_query->request)
            ? trim($wp_the_query->request)
            : null;

        $this->tracer?->completePrevious();
        $queries = is_object($wpdb) && isset($wpdb->queries) && is_array($wpdb->queries) ? $wpdb->queries : $queries;

        $formatter = $this->getDataFormatter();
        $statements = [];
        $total = 0.0;
        $failed = 0;

        foreach (array_values($queries) as $index => $query) {
            if ($index >= $this->hardLimit) {
                break;
            }

            $sql = trim((string) ($query[0] ?? ''));
            $duration = (float) ($query[1] ?? 0);
            $traced = is_array($query[4] ?? null) && is_array($query[4][QueryTracer::KEY] ?? null) ? $query[4][QueryTracer::KEY] : null;
            $total += $duration;

            if ($traced !== null && ($traced['frames'] ?? []) !== []) {
                $frames = array_map(fn (array $frame): string => sprintf('%s — %s:%d', $frame['call'], $this->relativePath($frame['file']), $frame['line']), $traced['frames']);
                $component = is_string($traced['component'] ?? null) ? $traced['component'] : $this->components?->ofTrace($traced['frames']);
                $source = $traced['frames'][0]['call'];
            } else {
                $frames = $index < $this->softLimit ? $this->frames((string) ($query[2] ?? '')) : [];
                $component = null;
                $source = $frames[0] ?? '';
            }

            $error = is_string($traced['error'] ?? null) ? $traced['error'] : null;
            $failed += $error !== null ? 1 : 0;

            $statements[] = [
                'sql' => $sql,
                'type' => 'query',
                'params' => (object) [],
                'duration' => $duration,
                'duration_str' => $formatter->formatDuration($duration),
                'memory_str' => '',
                'row_count' => is_int($traced['rows'] ?? null) ? $traced['rows'] : null,
                'is_success' => $error === null,
                'error_code' => null,
                'error_message' => $error,
                'backtrace' => $frames,
                'filename' => ($sql === $mainQuery ? 'main query · ' : '').$source,
                'xdebug_link' => null,
                'slow' => $this->slowThreshold !== null && $duration * 1000 >= $this->slowThreshold,
                'connection' => $component !== null ? "wpdb · {$component}" : 'wpdb',
            ];
        }

        $start = 0.0;

        foreach ($statements as $index => $statement) {
            $width = $total > 0 ? $statement['duration'] / $total * 100 : 0;
            $statements[$index]['start_percent'] = round($start, 3);
            $statements[$index]['width_percent'] = round($width, 3);
            $start += $width;
        }

        return [
            'nb_statements' => count($statements),
            'nb_excluded_statements' => max(0, count($queries) - count($statements)),
            'nb_failed_statements' => $failed,
            'accumulated_duration' => $total,
            'accumulated_duration_str' => $formatter->formatDuration($total),
            'memory_usage_str' => '',
            'statements' => $statements,
        ];
    }

    /**
     * WordPress's caller string, innermost call first.
     *
     * `$wpdb` stores the stack outermost first, joined by commas
     * (`require('wp-blog-header.php'), wp, WP->main, …`).
     *
     * @return list<string>
     */
    private function frames(string $caller): array
    {
        if ($caller === '') {
            return [];
        }

        return array_values(array_reverse(array_filter(array_map(trim(...), explode(',', $caller)))));
    }
}
