<?php

declare(strict_types=1);

namespace Pollora\Debugbar\Http;

use Fruitcake\LaravelDebugbar\LaravelDebugbar;
use Fruitcake\LaravelDebugbar\LaravelHttpDriver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the requests WordPress answers and then exits from — REST and
 * admin-ajax — in the debug bar.
 *
 * Laravel Debugbar collects when Laravel finishes a response. WordPress REST
 * and admin-ajax requests end with `exit` or `wp_die()` first, so on its own
 * the bar never stores them and the page that made the call never hears
 * about them. Here the request id goes out in the `phpdebugbar-id` header
 * before any output, and the data is collected and stored at `shutdown`. The
 * bar's fetch and XHR capture then lists the call like any other.
 *
 * `wp_redirect()` is followed by `exit` too. The request is stacked as
 * Debugbar stacks a Laravel redirect, so the next page shows both: its HTTP
 * driver can only set its cookie on a Laravel response, so it is given one,
 * and the cookie is sent by hand.
 */
final class WordPressExitResponder
{
    /**
     * @param  \Closure(): ?LaravelDebugbar  $debugbar  Resolves the bar once it is collecting, null before
     * @param  \Closure(LaravelDebugbar): void  $prepare  Adds the collectors before collection
     */
    public function __construct(
        private readonly \Closure $debugbar,
        private readonly \Closure $prepare,
    ) {}

    public function install(): void
    {
        if (! function_exists('add_action')) {
            return;
        }

        add_filter('rest_post_dispatch', $this->tagRestResponse(...), 10, 1);
        add_action('admin_init', $this->tagAjaxResponse(...), 0, 0);
        add_action('shutdown', $this->collect(...), 0, 0);
        add_filter('wp_redirect', function (mixed $location, mixed $status): mixed {
            if (is_string($location) && $location !== '') {
                $this->stackRedirect($location, (int) $status);
            }

            return $location;
        }, PHP_INT_MAX, 2);
    }

    /**
     * Keep this request for the page the redirect leads to.
     */
    public function stackRedirect(string $location, int $status): void
    {
        $debugbar = ($this->debugbar)();

        if (! $debugbar instanceof LaravelDebugbar) {
            return;
        }

        $debugbar->addMessage(sprintf('wp_redirect() %d to %s', $status, $location), 'redirect');

        $driver = $debugbar->getHttpDriver();

        if (headers_sent() || ! $driver instanceof LaravelHttpDriver) {
            return;
        }

        $response = new Response;

        // A debugging tool must never break the redirect it watches
        try {
            ($this->prepare)($debugbar);
            $driver->setResponse($response);
            $debugbar->stackData();
        } catch (\Throwable $throwable) {
            report($throwable);

            return;
        } finally {
            $driver->setResponse(null);
        }

        foreach ($response->headers->getCookies() as $cookie) {
            header('Set-Cookie: '.$cookie, false);
        }
    }

    /**
     * Name the stored request in the REST response's headers.
     */
    public function tagRestResponse(mixed $response): mixed
    {
        $debugbar = $this->storingDebugbar();

        if ($debugbar instanceof LaravelDebugbar && $response instanceof \WP_REST_Response) {
            $response->header('phpdebugbar-id', $debugbar->getCurrentRequestId());
        }

        return $response;
    }

    /**
     * Name the stored request before admin-ajax prints anything.
     *
     * `admin_init` runs in admin-ajax.php before the `wp_ajax_*` handler, so
     * headers can still be sent.
     */
    public function tagAjaxResponse(): void
    {
        $debugbar = $this->storingDebugbar();

        if ($debugbar instanceof LaravelDebugbar && $this->isAjax() && ! headers_sent()) {
            header('phpdebugbar-id: '.$debugbar->getCurrentRequestId());
        }
    }

    /**
     * Collect and store what Laravel's own lifecycle never will.
     *
     * Only for the requests that exit before Laravel finishes: on a page
     * Laravel renders, Pollora fires `shutdown` from a middleware, well before
     * the response is done.
     */
    public function collect(): void
    {
        if (! $this->isRest() && ! $this->isAjax()) {
            return;
        }

        $debugbar = $this->storingDebugbar();

        if (! $debugbar instanceof LaravelDebugbar) {
            return;
        }

        try {
            ($this->prepare)($debugbar);

            // getData() collects, and stores, only when nothing has been collected yet.
            $debugbar->getData();
        } catch (\Throwable $throwable) {
            report($throwable);
        }
    }

    private function storingDebugbar(): ?LaravelDebugbar
    {
        $debugbar = ($this->debugbar)();

        return $debugbar instanceof LaravelDebugbar && $debugbar->isDataPersisted() ? $debugbar : null;
    }

    private function isRest(): bool
    {
        return defined('REST_REQUEST') && REST_REQUEST;
    }

    private function isAjax(): bool
    {
        return function_exists('wp_doing_ajax') && wp_doing_ajax();
    }
}
