<?php

declare(strict_types=1);

use Pollora\Debugbar\Collectors\WpHooksCollector;
use Pollora\Debugbar\Recording\RequestRecorder;

final class FakePolloraHooks
{
    public function all(): array
    {
        return ['init' => [['hook' => 'init', 'callback' => [new ArrayObject, 'count'], 'priority' => 5, 'args' => 0]]];
    }
}

beforeEach(function (): void {
    $GLOBALS['wp_actions'] = ['muplugins_loaded' => 1, 'init' => 1, 'wp_head' => 2];
    $GLOBALS['wp_filter'] = [
        'init' => (object) ['callbacks' => [5 => ['a' => [], 'b' => []], 10 => ['c' => []]]],
    ];
});

afterEach(function (): void {
    unset($GLOBALS['wp_actions'], $GLOBALS['wp_filter']);
});

it('lists the actions that ran, in order, with their callbacks', function (): void {
    $rows = (new WpHooksCollector(new RequestRecorder))->collect()['data']['data'];

    expect(array_keys($rows))->toBe(['muplugins_loaded', 'init', 'wp_head'])
        ->and($rows['init']['callbacks'])->toBe(3)
        ->and($rows['wp_head']['calls'])->toBe(2);
});

it('names the callbacks Pollora registered, with their priority', function (): void {
    $rows = (new WpHooksCollector(new RequestRecorder, [new FakePolloraHooks]))->collect()['data']['data'];

    expect($rows['init']['pollora'])->toBe('ArrayObject::count @5')
        ->and($rows['wp_head']['pollora'])->toBe('');
});

it('describes callbacks the way a reader recognises them', function (): void {
    expect(WpHooksCollector::describe('wp_head'))->toBe('wp_head')
        ->and(WpHooksCollector::describe(['Acme\\Cart', 'boot']))->toBe('Acme\\Cart::boot')
        ->and(WpHooksCollector::describe(fn (): null => null))->toMatch('/^closure in P\\\\Tests\\\\Unit\\\\Collectors\\\\WpHooksCollectorTest\\S* \\(WpHooksCollectorTest\\.php:\\d+\\)$/')
        ->and(WpHooksCollector::describe((new ArrayObject)->count(...)))->toBe('ArrayObject::count');
});
