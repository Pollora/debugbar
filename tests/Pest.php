<?php

declare(strict_types=1);

use Pollora\Debugbar\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Brain Monkey stands in for WordPress in every test, so collectors and
| recorders run without WordPress loaded. Feature tests also get a Laravel
| application from Testbench, with Laravel Debugbar registered.
|
*/

uses()
    ->beforeEach(function (): void {
        Brain\Monkey\setUp();
    })
    ->afterEach(function (): void {
        Brain\Monkey\tearDown();
    })
    ->in('Unit');

uses(TestCase::class)
    ->beforeEach(function (): void {
        Brain\Monkey\setUp();
    })
    ->afterEach(function (): void {
        Brain\Monkey\tearDown();
    })
    ->in('Feature');
