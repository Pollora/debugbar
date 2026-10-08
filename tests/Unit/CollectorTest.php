<?php

declare(strict_types=1);

use DebugBar\DataFormatter\DataFormatter;
use DebugBar\DataFormatter\HtmlDataFormatter;
use DebugBar\DataFormatter\JsonDataFormatter;
use Pollora\Debugbar\Collector;
use Pollora\Debugbar\Origin;
use Pollora\Debugbar\Widget;

/**
 * @param  array<array-key, mixed>  $data
 */
function collectorShowing(array $data, Widget $widget = Widget::Variables, string $origin = 'acme-shop'): Collector
{
    return new class($data, $widget, $origin) extends Collector
    {
        /**
         * @param  array<array-key, mixed>  $rows
         */
        public function __construct(private readonly array $rows, private readonly Widget $shape, private readonly string $from) {}

        public function getName(): string
        {
            return 'acme_cart';
        }

        public function title(): string
        {
            return 'Acme cart';
        }

        public function origin(): string
        {
            return $this->from;
        }

        public function widget(): Widget
        {
            return $this->shape;
        }

        public function columns(): array
        {
            return ['qty' => 'Quantity'];
        }

        protected function data(): array
        {
            return $this->rows;
        }
    };
}

describe('variables', function (): void {
    it('escapes strings the HTML widget would write as markup', function (): void {
        $collector = collectorShowing(['<b>name</b>' => '<script>alert(1)</script>'])
            ->setDataFormatter(new HtmlDataFormatter);

        $data = $collector->collect()['data'];

        expect($data)->toBe(['&lt;b&gt;name&lt;/b&gt;' => '&lt;script&gt;alert(1)&lt;/script&gt;']);
    });

    it('escapes string values for the JSON widget, which renders them as HTML too', function (): void {
        $collector = collectorShowing(['name' => '<i>x</i>'])->setDataFormatter(new JsonDataFormatter);

        expect($collector->collect()['data'])->toBe(['name' => '&lt;i&gt;x&lt;/i&gt;'])
            ->and($collector->getWidgets()['acme_cart']['widget'])->toBe('PhpDebugBar.Widgets.JsonVariableListWidget');
    });

    it('reads booleans and nulls as words', function (): void {
        $collector = collectorShowing(['cached' => true, 'cart' => null])->setDataFormatter(new DataFormatter);

        expect($collector->collect()['data'])->toBe(['cached' => 'yes', 'cart' => '—']);
    });

    it('appends sections after its own values, each name prefixed by its title', function (): void {
        $collector = collectorShowing(['own' => 'value'])->setDataFormatter(new DataFormatter);
        $collector->addSection('Acme', fn (): array => ['cart' => '42']);

        expect(array_keys($collector->collect()['data']))->toBe(['own', 'Acme › cart']);
    });
});

describe('widgets', function (): void {
    it('places the tab in its origin band and names the origin in its tooltip', function (): void {
        $widgets = collectorShowing([], Widget::Variables, Origin::WORDPRESS)->getWidgets();

        expect($widgets['acme_cart']['order'])->toBe(2000)
            ->and($widgets['acme_cart']['tooltip'])->toBe('WordPress')
            ->and($widgets['acme_cart']['title'])->toBe('Acme cart');
    });

    it('puts third parties after Pollora and WordPress', function (): void {
        expect(Origin::band('acme-shop'))->toBeGreaterThan(Origin::band(Origin::WORDPRESS))
            ->and(Origin::band(Origin::WORDPRESS))->toBeGreaterThan(Origin::band(Origin::POLLORA));
    });

    it('shapes rows for the table widget, with their columns and a count for the badge', function (): void {
        $collector = collectorShowing(['apple' => ['qty' => 3]], Widget::Table);

        expect($collector->collect())->toBe([
            'data' => ['data' => ['apple' => ['qty' => 3]], 'key_map' => ['qty' => 'Quantity']],
            'count' => 1,
        ])->and($collector->getWidgets()['acme_cart:badge']['map'])->toBe('acme_cart.count');
    });

    it('asks for the SQL widget assets only when it shows queries', function (): void {
        expect(collectorShowing([], Widget::Queries)->getAssets())->toHaveKey('js', 'widgets/sqlqueries/widget.js')
            ->and(collectorShowing([])->getAssets())->toBe([]);
    });
});
