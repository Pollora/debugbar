<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Fruitcake\LaravelDebugbar\LaravelDebugbar;
use Pollora\Debugbar\Http\WordPressExitResponder;

require_once __DIR__.'/../Fixtures/wordpress.php';

/**
 * @return array{responder: WordPressExitResponder, prepared: ArrayObject}
 */
function responderFor(?LaravelDebugbar $debugbar, ?Closure $prepare = null): array
{
    $prepared = new ArrayObject;
    $responder = new WordPressExitResponder(fn () => $debugbar, $prepare ?? function (LaravelDebugbar $bar) use ($prepared): void {
        $prepared[] = $bar;
    });

    return ['responder' => $responder, 'prepared' => $prepared];
}

it('hooks the REST response, admin-ajax, shutdown and redirects when installed', function (): void {
    $added = [];

    foreach (['rest_post_dispatch', 'admin_init', 'shutdown', 'wp_redirect'] as $hook) {
        Actions\expectAdded($hook)->zeroOrMoreTimes()->whenHappen(function (callable $callback) use (&$added, $hook): void {
            $added[$hook] = $callback;
        });
        Filters\expectAdded($hook)->zeroOrMoreTimes()->whenHappen(function (callable $callback) use (&$added, $hook): void {
            $added[$hook] = $callback;
        });
    }

    responderFor(null)['responder']->install();

    expect(array_keys($added))->toEqualCanonicalizing(['rest_post_dispatch', 'admin_init', 'shutdown', 'wp_redirect']);
});

it('hands the redirect location back untouched, whatever it does with it', function (): void {
    $redirect = null;
    Filters\expectAdded('wp_redirect')->once()->whenHappen(function (callable $callback) use (&$redirect): void {
        $redirect = $callback;
    });
    responderFor(null)['responder']->install();

    expect($redirect('https://site.test/next', 302))->toBe('https://site.test/next')
        ->and($redirect('', 302))->toBe('')
        ->and($redirect(false, 302))->toBeFalse();
});

describe('stacking a redirect', function (): void {
    it('does nothing while the bar is not collecting', function (): void {
        $setup = responderFor(null);

        $setup['responder']->stackRedirect('https://site.test/next', 302);

        expect($setup['prepared'])->toHaveCount(0);
    });

    it('notes the redirect in the bar, and stacks the request for the next page when headers can still be sent', function (): void {
        if (headers_sent()) {
            $this->markTestSkipped('Output has started: the bar cannot set its cookie.');
        }

        $debugbar = $this->collectingDebugbar();
        $setup = responderFor($debugbar);

        $setup['responder']->stackRedirect('https://site.test/next', 301);

        $messages = collect($debugbar->getCollector('messages')->getMessages())->pluck('message')->all();

        expect($messages)->toContain('wp_redirect() 301 to https://site.test/next')
            ->and($setup['prepared'])->toHaveCount(1);
    });

    it('never breaks the redirect it watches', function (): void {
        if (headers_sent()) {
            $this->markTestSkipped('Output has started: the bar cannot set its cookie.');
        }

        $debugbar = $this->collectingDebugbar();
        $setup = responderFor($debugbar, function (): void {
            throw new RuntimeException('a collector failed');
        });

        $setup['responder']->stackRedirect('https://site.test/next', 302);

        expect($debugbar->getStorage()->saved)->toBe([]);
    });
});

describe('tagging admin-ajax', function (): void {
    it('does nothing outside admin-ajax', function (): void {
        Brain\Monkey\Functions\when('wp_doing_ajax')->justReturn(false);
        $debugbar = $this->collectingDebugbar();

        responderFor($debugbar)['responder']->tagAjaxResponse();

        expect(array_filter(headers_list(), fn (string $header): bool => str_starts_with($header, 'phpdebugbar-id')))->toBe([]);
    });

    it('does nothing when no request is stored to open later', function (): void {
        Brain\Monkey\Functions\when('wp_doing_ajax')->justReturn(true);
        $debugbar = $this->collectingDebugbar();
        $debugbar->setStorage(null);

        responderFor($debugbar)['responder']->tagAjaxResponse();

        expect(array_filter(headers_list(), fn (string $header): bool => str_starts_with($header, 'phpdebugbar-id')))->toBe([]);
    });
});

describe('collecting at shutdown', function (): void {
    it('does nothing when nothing is stored', function (): void {
        Brain\Monkey\Functions\when('wp_doing_ajax')->justReturn(true);
        $debugbar = $this->collectingDebugbar();
        $debugbar->setStorage(null);
        $setup = responderFor($debugbar);

        $setup['responder']->collect();

        expect($setup['prepared'])->toHaveCount(0);
    });

    it('never breaks the request it watches when a collector fails', function (): void {
        Brain\Monkey\Functions\when('wp_doing_ajax')->justReturn(true);
        $debugbar = $this->collectingDebugbar();
        $setup = responderFor($debugbar, function (): void {
            throw new RuntimeException('a collector failed');
        });

        $setup['responder']->collect();

        expect($debugbar->getStorage()->saved)->toBe([]);
    });
});

it('leaves a response that is not a REST response alone', function (): void {
    $debugbar = $this->collectingDebugbar();

    expect(responderFor($debugbar)['responder']->tagRestResponse('not a response'))->toBe('not a response');
});
