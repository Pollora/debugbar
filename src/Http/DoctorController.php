<?php

declare(strict_types=1);

namespace Pollora\Debugbar\Http;

use Fruitcake\LaravelDebugbar\LaravelDebugbar;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pollora\Doctor\Application\Services\Doctor;
use Pollora\Doctor\Domain\Enums\RunContext;

/**
 * Runs `pollora:doctor`'s web checks when the Doctor tab asks for them.
 *
 * Nothing runs with the page: the checks cost more than a request should, so
 * they run on demand, here. Guarded as Debugbar guards its stored requests:
 * the bar must be on, and the caller on a local or private address unless
 * the storage was opened on purpose.
 */
final class DoctorController
{
    public function __invoke(Request $request, LaravelDebugbar $debugbar, Container $container): JsonResponse
    {
        if (! $debugbar->isStorageOpen($request)) {
            return new JsonResponse(['message' => 'The doctor answers local and private addresses only, as Debugbar\'s stored requests do (debugbar.storage.open).'], 403);
        }

        if (! class_exists(Doctor::class)) {
            return new JsonResponse(['message' => 'pollora:doctor is not available in this version of the framework.'], 501);
        }

        $order = ['error' => 0, 'warning' => 1, 'ok' => 2, 'skipped' => 3];

        $checks = array_map(static fn (array $run): array => [
            'id' => $run['check']->id(),
            'label' => $run['check']->label(),
            'status' => $run['result']->status->value,
            'summary' => $run['result']->summary,
            'details' => $run['result']->details,
            'fix' => $run['result']->fix,
        ], $container->make(Doctor::class)->run(RunContext::Http));

        usort($checks, static fn (array $a, array $b): int => $order[$a['status']] <=> $order[$b['status']]);

        return new JsonResponse(['checks' => $checks]);
    }
}
