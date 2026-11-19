<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
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

it('shows the message of a WP_Error as WordPress would print it', function (): void {
    require_once __DIR__.'/../Fixtures/wordpress.php';
    $debugbar = $this->collectingDebugbar();

    (new MessageBridge(fn () => $debugbar))->message(new WP_Error('rest_forbidden', 'Sorry, you cannot do that.'), 'error');

    expect(messagesOf($debugbar))->toContain('WP_Error: Sorry, you cannot do that.');
});

/**
 * The measures the bridge made; the messages tab adds its own to the timeline.
 */
function wordpressMeasures(LaravelDebugbar $debugbar): array
{
    return array_values(array_filter($debugbar->getTimeCollector()->getMeasures(), static fn (array $measure): bool => $measure['collector'] === 'wordpress'));
}

function bridgeListeners(): ArrayObject
{
    $callbacks = new ArrayObject;

    foreach (['pollora/debugbar/message', 'pollora/debugbar/start', 'pollora/debugbar/stop', 'qm/start', 'qm/stop', ...array_map(fn (string $level): string => "qm/{$level}", MessageBridge::LEVELS)] as $action) {
        Actions\expectAdded($action)->zeroOrMoreTimes()->whenHappen(function (callable $callback) use ($callbacks, $action): void {
            $callbacks[$action] = $callback;
        });
    }

    return $callbacks;
}

it('times under the label it was given, or the name, in the WordPress group', function (): void {
    $debugbar = $this->collectingDebugbar();
    $bridge = new MessageBridge(fn () => $debugbar);

    $bridge->start('named');
    $bridge->stop('named');
    $bridge->start('labelled', 'A label');
    $bridge->stop('labelled');
    $measures = $debugbar->getTimeCollector()->getMeasures();

    expect(array_column($measures, 'label'))->toBe(['named', 'A label'])
        ->and(array_column($measures, 'group'))->toBe(['WordPress', 'WordPress'])
        ->and(array_column($measures, 'collector'))->toBe(['wordpress', 'wordpress']);
});

it('starts and stops nothing while the bar is not collecting', function (): void {
    $bridge = new MessageBridge(fn (): null => null);

    $bridge->start('lost');
    $bridge->stop('lost');
})->throwsNoExceptions();

it('fills the placeholders whose value can be written', function (): void {
    $debugbar = $this->collectingDebugbar();
    $stringable = new class implements Stringable
    {
        public function __toString(): string
        {
            return 'text';
        }
    };

    (new MessageBridge(fn () => $debugbar))->message('{a} {b} {e} {a}', 'info', ['a' => 1, 'b' => $stringable, 'e' => 1.5]);

    expect(messagesOf($debugbar))->toContain('1 text 1.5 1');
});

it('keeps a message that is not a string as it is, and passes its level and context on', function (): void {
    $debugbar = $this->collectingDebugbar();

    (new MessageBridge(fn () => $debugbar))->message(['rows' => 2], 'warning', ['k' => 'v']);
    $message = $debugbar->getMessagesCollector()->getMessages()[0];

    expect($message['message_json'])->toBe(['rows' => 2])
        ->and($message['is_string'])->toBeFalse()
        ->and($message['label'])->toBe('warning')
        ->and($message['context_json'])->toBe(['k' => 'v']);
});

it('logs through the Query Monitor action of each level, with its context', function (): void {
    $debugbar = $this->collectingDebugbar();
    $actions = bridgeListeners();
    (new MessageBridge(fn () => $debugbar))->install();

    foreach (MessageBridge::LEVELS as $level) {
        $actions["qm/{$level}"]("Level {id} {$level}", ['id' => 9]);
    }

    $actions['qm/info']('No context');
    $actions['qm/start']('timed');
    $actions['qm/stop']('timed');
    $messages = $debugbar->getMessagesCollector()->getMessages();

    expect(array_column($messages, 'label'))->toBe([...MessageBridge::LEVELS, 'info'])
        ->and($messages[0]['message'])->toBe('Level 9 debug')
        ->and($messages[7]['message'])->toBe('Level 9 emergency')
        ->and($messages[8]['message'])->toBe('No context')
        ->and(array_column(wordpressMeasures($debugbar), 'label'))->toBe(['timed']);
});

it('logs through its own actions with their level, and times with their label', function (): void {
    $debugbar = $this->collectingDebugbar();
    $actions = bridgeListeners();
    (new MessageBridge(fn () => $debugbar))->install(queryMonitor: false);

    $actions['pollora/debugbar/message']('Hello {who}', 'notice', ['who' => 'you']);
    $actions['pollora/debugbar/start']('job', 'The job');
    $actions['pollora/debugbar/stop']('job');

    expect(array_column($debugbar->getMessagesCollector()->getMessages(), 'message'))->toBe(['Hello you'])
        ->and(array_column($debugbar->getMessagesCollector()->getMessages(), 'label'))->toBe(['notice'])
        ->and(array_column(wordpressMeasures($debugbar), 'label'))->toBe(['The job']);
});
