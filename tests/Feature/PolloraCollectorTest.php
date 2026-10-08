<?php

declare(strict_types=1);

use DebugBar\DataFormatter\DataFormatter;
use Illuminate\Support\Facades\Route;
use Pollora\Debugbar\Collectors\PolloraCollector;
use Pollora\Route\Application\Services\AnsweringTemplate;
use Pollora\Route\Domain\Enums\TemplateOutcome;
use Pollora\Route\Domain\Models\TemplateResolution;

function polloraData(): array
{
    return (new PolloraCollector(app()))->setDataFormatter(new DataFormatter)->collect()['data'];
}

it('says the template hierarchy answered, and with which view', function (): void {
    $answering = new AnsweringTemplate;
    $answering->record(new TemplateResolution(
        template: base_path('themes/acme/resources/views/single.blade.php'),
        condition: 'is_single',
        view: 'single',
        usedIndexFallback: false,
        outcome: TemplateOutcome::View,
    ));
    $this->app->instance(AnsweringTemplate::class, $answering);

    $data = polloraData();

    expect($data['Answered by'])->toBe('Template hierarchy (catch-all route)')
        ->and($data['Template'])->toContain('themes/acme/resources/views/single.blade.php')
        ->and($data['Template'])->toContain('is_single');
});

it('names the Laravel route that answered', function (): void {
    Route::get('/shop/search', fn () => 'ok');
    $this->get('/shop/search');

    expect(polloraData()['Answered by'])->toBe('GET|HEAD shop/search → Closure');
});

it('says WordPress answered alone when no route did', function (): void {
    Brain\Monkey\Functions\stubs(['is_admin' => false, 'wp_doing_ajax' => false]);

    expect(polloraData()['Answered by'])->toBe('WordPress, outside any Laravel route');
});

it('keeps the tab when one part cannot be read', function (): void {
    $this->app->bind(AnsweringTemplate::class, fn () => throw new RuntimeException('not bound here'));

    expect(polloraData()['Answered by'])->toBe('unavailable: not bound here')
        ->and(polloraData()['Versions'])->toContain(PHP_VERSION);
});
