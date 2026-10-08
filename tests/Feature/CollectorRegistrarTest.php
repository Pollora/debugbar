<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use DebugBar\DataCollector\MessagesCollector;
use Pollora\Debugbar\CollectorRegistrar;
use Pollora\Debugbar\Contracts\SectionProvider;

function registrar(): CollectorRegistrar
{
    return app(CollectorRegistrar::class);
}

it("adds Pollora's and WordPress's tabs", function (): void {
    $debugbar = $this->collectingDebugbar();

    registrar()->register($debugbar);

    foreach (['pollora', 'wp_request', 'wp_queries', 'wp_hooks', 'wp_timeline'] as $name) {
        expect($debugbar->hasCollector($name))->toBeTrue();
    }
});

it('leaves out the tabs turned off in its config', function (): void {
    config(['debugbar-pollora.collectors.wp_hooks' => false]);
    $debugbar = $this->collectingDebugbar();

    registrar()->register($debugbar);

    expect($debugbar->hasCollector('wp_hooks'))->toBeFalse();
});

it('adds collectors tagged in the container (level 2)', function (): void {
    $this->app->instance('acme.collector', new MessagesCollector('acme_log'));
    $this->app->tag('acme.collector', CollectorRegistrar::COLLECTORS_TAG);
    $debugbar = $this->collectingDebugbar();

    registrar()->register($debugbar);

    expect($debugbar->hasCollector('acme_log'))->toBeTrue();
});

it('adds the tabs and sections WordPress code registers through the action (level 3)', function (): void {
    Functions\when('do_action')->alias(function (string $hook, mixed ...$arguments): void {
        if ($hook === 'pollora/debugbar/register') {
            $arguments[0]
                ->table('acme_cart', 'Acme cart', fn (): array => ['apple' => ['qty' => 3]], 'acme-shop')
                ->section('pollora', 'Acme', fn (): array => ['cart' => 'abc']);
        }
    });
    $debugbar = $this->collectingDebugbar();

    registrar()->register($debugbar);

    expect($debugbar->hasCollector('acme_cart'))->toBeTrue()
        ->and($debugbar->getCollector('acme_cart')->getWidgets()['acme_cart']['order'])->toBe(3000)
        ->and(array_keys($debugbar->getCollector('pollora')->collect()['data']))->toContain('Acme › cart');
});

it('adds sections from tagged providers', function (): void {
    $this->app->instance('acme.section', new class implements SectionProvider
    {
        public function tab(): string
        {
            return 'wp_request';
        }

        public function title(): string
        {
            return 'Acme';
        }

        public function values(): array
        {
            return ['locale' => 'fr_FR'];
        }
    });
    $this->app->tag('acme.section', CollectorRegistrar::SECTIONS_TAG);
    Functions\when('get_queried_object')->justReturn(null);
    $debugbar = $this->collectingDebugbar();

    registrar()->register($debugbar);

    expect($debugbar->getCollector('wp_request')->collect()['data'])->toHaveKey('Acme › locale', 'fr_FR');
});

it('keeps the first of two tabs with the same name and says so', function (): void {
    $this->app->instance('acme.one', new MessagesCollector('acme_log'));
    $this->app->instance('acme.two', new MessagesCollector('acme_log'));
    $this->app->tag(['acme.one', 'acme.two'], CollectorRegistrar::COLLECTORS_TAG);
    $debugbar = $this->collectingDebugbar();

    registrar()->register($debugbar);

    expect(array_column($debugbar->getMessagesCollector()->getMessages(), 'message'))
        ->toContain('pollora/debugbar: a tab named "acme_log" already exists; the second one was left out.');
});

it('registers once, however often it is asked', function (): void {
    $debugbar = $this->collectingDebugbar();

    registrar()->register($debugbar);
    registrar()->register($debugbar);

    expect(registrar()->isRegistered())->toBeTrue();
});
