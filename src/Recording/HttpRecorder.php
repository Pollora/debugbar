<?php

declare(strict_types=1);

namespace Pollora\Debugbar\Recording;

/**
 * Records the HTTP calls WordPress makes (`wp_remote_*`): when each starts,
 * how it ends, and who asked for it.
 *
 * Each call is tagged in its own arguments as it starts, so the end — a
 * response, an error, or a plugin answering in its place through
 * `pre_http_request` — finds the right start even when calls overlap.
 */
final class HttpRecorder
{
    private const string TAG = '_pollora_debugbar_call';

    private bool $installed = false;

    /**
     * @var array<string, array{method: string, url: string, start: float, end: float|null, status: int|string|null, error: string|null, transport: string|null, shortCircuited: bool, frames: list<array{file: string}>}>
     */
    private array $calls = [];

    public function install(): void
    {
        if ($this->installed || ! function_exists('add_filter')) {
            return;
        }

        $this->installed = true;

        add_filter('http_request_args', fn (mixed $args, mixed $url): mixed => $this->starts($args, (string) $url), PHP_INT_MAX, 2);
        add_filter('pre_http_request', fn (mixed $pre, mixed $args): mixed => $this->answeredInPlace($pre, $args), PHP_INT_MAX, 2);
        add_action('http_api_debug', function (mixed $response, mixed $context, mixed $transport, mixed $args): void {
            $this->ends($response, $args, is_string($transport) ? $transport : null);
        }, PHP_INT_MAX, 4);
    }

    /**
     * @return list<array{method: string, url: string, start: float, end: float|null, status: int|string|null, error: string|null, transport: string|null, shortCircuited: bool, frames: list<array{file: string}>}>
     */
    public function calls(): array
    {
        return array_values($this->calls);
    }

    public function reset(): void
    {
        $this->calls = [];
    }

    private function starts(mixed $args, string $url): mixed
    {
        if (! is_array($args)) {
            return $args;
        }

        $id = (string) count($this->calls);
        $args[self::TAG] = $id;

        $this->calls[$id] = [
            'method' => strtoupper(is_string($args['method'] ?? null) ? $args['method'] : 'GET'),
            'url' => $url,
            'start' => microtime(true),
            'end' => null,
            'status' => null,
            'error' => null,
            'transport' => null,
            'shortCircuited' => false,
            'frames' => array_values(array_filter(
                array_map(static fn (array $frame): array => ['file' => $frame['file'] ?? ''], debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20)),
                static fn (array $frame): bool => $frame['file'] !== '',
            )),
        ];

        return $args;
    }

    private function answeredInPlace(mixed $pre, mixed $args): mixed
    {
        if ($pre !== false && is_array($args) && isset($this->calls[$args[self::TAG] ?? ''])) {
            $id = $args[self::TAG];
            $this->calls[$id]['shortCircuited'] = true;
            $this->finish($id, $pre, null);
        }

        return $pre;
    }

    private function ends(mixed $response, mixed $args, ?string $transport): void
    {
        if (is_array($args) && isset($this->calls[$args[self::TAG] ?? ''])) {
            $this->finish($args[self::TAG], $response, $transport);
        }
    }

    private function finish(string $id, mixed $response, ?string $transport): void
    {
        $this->calls[$id]['end'] = microtime(true);
        $this->calls[$id]['transport'] = $transport;

        if ($response instanceof \WP_Error) {
            $this->calls[$id]['error'] = $response->get_error_message();

            return;
        }

        if (is_array($response)) {
            $code = $response['response']['code'] ?? null;
            $this->calls[$id]['status'] = is_int($code) || is_string($code) ? $code : null;
        }
    }
}
