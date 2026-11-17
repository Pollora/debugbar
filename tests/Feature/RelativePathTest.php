<?php

declare(strict_types=1);

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use DebugBar\DataFormatter\DataFormatter;
use Pollora\Debugbar\Collectors\WpRequestCollector;
use Pollora\Debugbar\Recording\RequestRecorder;

function templateShownFor(string $template): mixed
{
    Functions\when('get_queried_object')->justReturn(null);
    $callback = null;
    Filters\expectAdded('template_include')->once()->whenHappen(function (callable $added) use (&$callback): void {
        $callback = $added;
    });
    $recorder = new RequestRecorder;
    $recorder->install();
    $callback($template);

    $collector = (new WpRequestCollector($recorder))->setDataFormatter(new DataFormatter);

    return (new ReflectionMethod($collector, 'data'))->invoke($collector)['Template'];
}

it('shows a file of the project by its path from the project root', function (): void {
    expect(templateShownFor(base_path('themes/acme/single.php')))->toBe('themes/acme/single.php');
});

it('shows a file outside the project, or one whose folder only starts like the root, as it is', function (): void {
    $outside = '/elsewhere/single.php';
    $sibling = rtrim(base_path(), '/').'-other/single.php';

    expect(templateShownFor($outside))->toBe($outside)
        ->and(templateShownFor($sibling))->toBe($sibling);
});
