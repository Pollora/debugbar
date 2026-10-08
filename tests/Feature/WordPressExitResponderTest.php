<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Debugbar\Http\WordPressExitResponder;

if (! class_exists(WP_REST_Response::class)) {
    class WP_REST_Response
    {
        /** @var array<string, string> */
        public array $headers = [];

        public function header(string $key, string $value): void
        {
            $this->headers[$key] = $value;
        }
    }
}

it('names the stored request in a REST response', function (): void {
    $debugbar = $this->collectingDebugbar();

    $response = (new WordPressExitResponder(fn () => $debugbar, fn () => null))->tagRestResponse(new WP_REST_Response);

    expect($response->headers)->toBe(['phpdebugbar-id' => $debugbar->getCurrentRequestId()]);
});

it('leaves REST responses alone when nothing is stored to open later', function (): void {
    $debugbar = $this->collectingDebugbar();
    $debugbar->setStorage(null);

    $response = (new WordPressExitResponder(fn () => $debugbar, fn () => null))->tagRestResponse(new WP_REST_Response);

    expect($response->headers)->toBe([]);
});

it('collects and stores an admin-ajax request at shutdown, after adding the tabs', function (): void {
    Functions\when('wp_doing_ajax')->justReturn(true);
    $debugbar = $this->collectingDebugbar();
    $prepared = false;

    (new WordPressExitResponder(fn () => $debugbar, function () use (&$prepared): void {
        $prepared = true;
    }))->collect();

    expect($prepared)->toBeTrue()
        ->and($debugbar->getStorage()->saved)->toHaveKey($debugbar->getCurrentRequestId());
});

it('leaves pages Laravel renders to Laravel, since Pollora fires shutdown early there', function (): void {
    Functions\when('wp_doing_ajax')->justReturn(false);
    $debugbar = $this->collectingDebugbar();

    (new WordPressExitResponder(fn () => $debugbar, fn () => null))->collect();

    expect($debugbar->getStorage()->saved)->toBe([]);
});
