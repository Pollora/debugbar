<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

/*
 * ARCH-07: the harness preset (WordPress classes and functions, the tests), plus what is specific to this package.
 */
$configuration = require __DIR__.'/vendor/pollora/harness/presets/composer-dependency-analyser.php';

return $configuration
    // The debugbar runs inside Pollora: the framework brings Laravel, the Debugbar library and the packages the collectors read.
    ->ignoreErrorsOnPackages([
        'laravel/framework',
        'php-debugbar/php-debugbar',
        'pollora/assets',
        'pollora/block-bindings',
        'pollora/discovery',
        'pollora/hook',
        'symfony/http-foundation',
    ], [ErrorType::SHADOW_DEPENDENCY])
    // opcache_get_status() exists where OPcache is loaded; the collector checks function_exists() first.
    ->ignoreErrorsOnExtension('ext-zend-opcache', [ErrorType::SHADOW_DEPENDENCY])
    // WordPress's own dependency class, not a Composer one.
    ->ignoreUnknownClasses(['_WP_Dependency']);
