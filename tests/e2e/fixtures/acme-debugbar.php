<?php

/**
 * Plugin Name: Acme debug bar fixture
 * Description: Adds data to the debug bar the way a plugin and a package would.
 *
 * Level 3 (WordPress actions only) and level 2 (a Collector subclass tagged in
 * the container). Copied to mu-plugins by the end-to-end job; not shipped.
 */

declare(strict_types=1);

use Pollora\Debugbar\Collector;
use Pollora\Debugbar\CollectorRegistrar;
use Pollora\Debugbar\Widget;

add_action('pollora/debugbar/register', function ($bar): void {
    $bar->table('acme_cart', 'Acme cart', fn (): array => ['apple' => ['qty' => 3]], 'acme-shop', ['qty' => 'Qty']);
    $bar->section('wp_request', 'Acme', fn (): array => ['cart id' => 'c-42']);
});

add_action('init', function (): void {
    do_action('pollora/debugbar/message', 'Acme cart {id} rebuilt', 'info', ['id' => 'c-42']);
    do_action('qm/warning', 'Written for Query Monitor');

    // An HTTP call answered in place, so the test needs no network
    add_filter('pre_http_request', function ($pre, $args, $url) {
        return str_starts_with($url, 'https://api.acme.test/')
            ? ['headers' => [], 'body' => '{}', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null]
            : $pre;
    }, 10, 3);
    wp_remote_get('https://api.acme.test/stock');

    // WordPress only announces a transient whose value changed
    set_transient('acme_rates', ['eur' => 1.0, 'at' => microtime(true)], HOUR_IN_SECONDS);
    current_user_can('edit_posts');
});

if (class_exists(Collector::class)) {
    final class AcmeOrdersCollector extends Collector
    {
        public function getName(): string
        {
            return 'acme_orders';
        }

        public function title(): string
        {
            return 'Acme orders';
        }

        public function origin(): string
        {
            return 'acme-shop';
        }

        public function widget(): Widget
        {
            return Widget::Variables;
        }

        protected function data(): array
        {
            return ['open orders' => 2];
        }
    }

    app()->tag([AcmeOrdersCollector::class], CollectorRegistrar::COLLECTORS_TAG);
}
