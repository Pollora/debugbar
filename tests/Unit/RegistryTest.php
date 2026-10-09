<?php

declare(strict_types=1);

use Pollora\Debugbar\Origin;
use Pollora\Debugbar\Registry;
use Pollora\Debugbar\Widget;

it('builds a tab per call, with the origin given', function (): void {
    $registry = (new Registry)
        ->variables('acme_info', 'Acme', fn (): array => ['a' => 1], 'acme-shop')
        ->table('acme_cart', 'Acme cart', fn (): array => [], 'acme-shop', ['qty' => 'Quantity']);

    [$info, $cart] = $registry->collectors();

    expect($info->getName())->toBe('acme_info')
        ->and($info->origin())->toBe('acme-shop')
        ->and($cart->widget())->toBe(Widget::Table)
        ->and($cart->columns())->toBe(['qty' => 'Quantity']);
});

it('keeps sections for the tab they name', function (): void {
    $registry = (new Registry)->section('wp_request', 'Acme', fn (): array => []);

    expect($registry->sections()[0]['tab'])->toBe('wp_request')
        ->and($registry->sections()[0]['title'])->toBe('Acme');
});

it("refuses a third party a name that belongs to Pollora's tabs", function (string $name): void {
    (new Registry)->variables($name, 'Mine', fn (): array => [], 'acme-shop');
})->with(['wp_queries', 'wp_cart', 'pollora', 'pollora_extra'])->throws(InvalidArgumentException::class);

it('knows which names are reserved', function (): void {
    expect(Origin::isReservedName('wp_hooks'))->toBeTrue()
        ->and(Origin::isReservedName('acme_wp'))->toBeFalse();
});
