<?php

declare(strict_types=1);

use Fruitcake\LaravelDebugbar\LaravelDebugbar;
use Pollora\Debugbar\Bridges\MessageBridge;

function messagesOf(LaravelDebugbar $debugbar): array
{
    return array_column($debugbar->getMessagesCollector()->getMessages(), 'message');
}

it("fills Query Monitor's placeholders from the context", function (): void {
    $debugbar = $this->collectingDebugbar();

    (new MessageBridge(fn () => $debugbar))->message('Cart {cart} rebuilt', 'warning', ['cart' => 42]);

    expect(messagesOf($debugbar))->toContain('Cart 42 rebuilt');
});

it('sends throwables to the exceptions tab', function (): void {
    $debugbar = $this->collectingDebugbar();

    (new MessageBridge(fn () => $debugbar))->message(new RuntimeException('boom'));

    expect($debugbar->getExceptionsCollector()->getExceptions())->toHaveCount(1);
});

it('times between start and stop on the timeline', function (): void {
    $debugbar = $this->collectingDebugbar();
    $bridge = new MessageBridge(fn () => $debugbar);

    $bridge->start('acme-sync');
    $bridge->stop('acme-sync');

    expect(array_column($debugbar->getTimeCollector()->getMeasures(), 'label'))->toContain('acme-sync');
});

it('drops messages while the bar is not collecting', function (): void {
    (new MessageBridge(fn (): null => null))->message('lost');
})->throwsNoExceptions();

it("listens to Query Monitor's actions only when asked to", function (): void {
    (new MessageBridge(fn (): null => null))->install(queryMonitor: false);

    expect(has_action('pollora/debugbar/message'))->toBeTrue()
        ->and(has_action('qm/debug'))->toBeFalse();
});

it('listens to every Query Monitor level', function (): void {
    (new MessageBridge(fn (): null => null))->install();

    foreach ([...array_map(fn (string $level): string => "qm/{$level}", MessageBridge::LEVELS), 'qm/start', 'qm/stop'] as $action) {
        expect(has_action($action))->toBeTrue();
    }
});
