<?php

declare(strict_types=1);

use DebugBar\DataFormatter\DataFormatter;
use Pollora\Debugbar\Collectors\WpCapabilitiesCollector;
use Pollora\Debugbar\Collectors\WpHttpCollector;
use Pollora\Debugbar\Recording\CapabilityRecorder;
use Pollora\Debugbar\Recording\HttpRecorder;
use Pollora\Debugbar\Support\Components;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function httpCall(array $overrides = []): array
{
    return [...[
        'method' => 'GET',
        'url' => 'https://a.test',
        'start' => 10.0,
        'end' => 10.5,
        'status' => 200,
        'error' => null,
        'transport' => 'curl',
        'shortCircuited' => false,
        'frames' => [],
    ], ...$overrides];
}

function httpRows(array $calls): array
{
    $recorder = new HttpRecorder;
    (new ReflectionProperty($recorder, 'calls'))->setValue($recorder, $calls);

    return (new WpHttpCollector($recorder, new Components([])))->setDataFormatter(new DataFormatter)->collect()['data']['data'];
}

it('shows each HTTP call numbered from one, with its result, time, transport and origin', function (): void {
    $half = (new DataFormatter)->formatDuration(0.5);

    $rows = httpRows([
        httpCall(),
        httpCall(['method' => 'POST', 'url' => 'https://b.test', 'status' => null]),
        httpCall(['url' => 'https://c.test', 'error' => 'cURL error 28', 'status' => null, 'transport' => null]),
        httpCall(['url' => 'https://d.test', 'end' => null, 'transport' => null]),
        httpCall(['url' => 'https://e.test', 'shortCircuited' => true, 'status' => 204, 'transport' => null]),
        httpCall(['url' => 'https://f.test', 'shortCircuited' => true, 'status' => null, 'transport' => null]),
    ]);

    expect($rows)->toBe([
        '1. GET https://a.test' => ['result' => '200', 'time' => $half, 'transport' => 'curl', 'component' => 'unknown'],
        '2. POST https://b.test' => ['result' => '—', 'time' => $half, 'transport' => 'curl', 'component' => 'unknown'],
        '3. GET https://c.test' => ['result' => 'error: cURL error 28', 'time' => $half, 'transport' => '—', 'component' => 'unknown'],
        '4. GET https://d.test' => ['result' => 'no response recorded', 'time' => '—', 'transport' => '—', 'component' => 'unknown'],
        '5. GET https://e.test' => ['result' => '204 (answered by pre_http_request)', 'time' => $half, 'transport' => '—', 'component' => 'unknown'],
        '6. GET https://f.test' => ['result' => 'answered (answered by pre_http_request)', 'time' => $half, 'transport' => '—', 'component' => 'unknown'],
    ]);
});

it('shows each capability check with its result, user, count and origin', function (): void {
    $recorder = new CapabilityRecorder;
    (new ReflectionProperty($recorder, 'checks'))->setValue($recorder, [
        'a' => ['user' => 5, 'capability' => 'edit_post', 'arguments' => '42', 'granted' => true, 'count' => 3, 'frames' => [['file' => '/x/a.php']]],
        'b' => ['user' => 0, 'capability' => 'read', 'arguments' => '', 'granted' => false, 'count' => 1, 'frames' => []],
        'c' => ['user' => 6, 'capability' => 'read', 'arguments' => '', 'granted' => true, 'count' => 2, 'frames' => []],
    ]);

    $rows = (new WpCapabilitiesCollector($recorder, new Components(['/x/' => ['kind' => 'plugin', 'name' => 'acme']])))
        ->setDataFormatter(new DataFormatter)->collect()['data']['data'];

    expect($rows)->toBe([
        'edit_post(42)' => ['result' => 'granted', 'user' => '#5', 'count' => 3, 'component' => 'plugin: acme'],
        'read' => ['result' => 'refused', 'user' => 'visitor', 'count' => 1, 'component' => '—'],
        'read ' => ['result' => 'granted', 'user' => '#6', 'count' => 2, 'component' => '—'],
    ]);
});
