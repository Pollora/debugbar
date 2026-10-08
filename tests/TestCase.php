<?php

declare(strict_types=1);

namespace Pollora\Debugbar\Tests;

use Fruitcake\LaravelDebugbar\LaravelDebugbar;
use Fruitcake\LaravelDebugbar\ServiceProvider as DebugbarServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Pollora\Debugbar\DebugbarServiceProvider as PolloraDebugbarServiceProvider;
use Pollora\Debugbar\Tests\Fixtures\MemoryStorage;

abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [DebugbarServiceProvider::class, PolloraDebugbarServiceProvider::class];
    }

    /**
     * The bar, switched on as it would be on a debug page, storing in memory.
     *
     * Testbench runs in the console, where Debugbar stays off by itself.
     */
    protected function collectingDebugbar(): LaravelDebugbar
    {
        $debugbar = $this->app->make(LaravelDebugbar::class);
        $debugbar->enable();
        $debugbar->setStorage(new MemoryStorage);

        return $debugbar;
    }
}
