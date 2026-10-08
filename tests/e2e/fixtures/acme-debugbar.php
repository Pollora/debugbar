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
