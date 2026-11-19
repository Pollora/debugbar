<?php

declare(strict_types=1);

/*
 * Run in a process of its own by ComponentsTest: it defines the constants
 * WordPress defines, which would stay defined for every other test.
 *
 * Usage: php components-probe.php <define: yes|no> <file>...
 */

require __DIR__.'/../../vendor/autoload.php';

use Pollora\Debugbar\Support\Components;

$files = array_slice($argv, 2);

if ($argv[1] === 'yes') {
    define('WP_PLUGIN_DIR', '/wp/content/plugins/');
    define('WPMU_PLUGIN_DIR', '/wp/content/mu-plugins');
    define('ABSPATH', '/wp/cms/');

    function get_theme_root(): string
    {
        return '/wp/content/themes';
    }
}

$components = new Components;
$answers = [];

foreach ($files as $file) {
    $answers[$file] = $components->of($file);
}

$kept = (new ReflectionProperty($components, 'directories'))->getValue($components);

echo json_encode(['answers' => $answers, 'kept' => $kept === null ? null : array_keys($kept)], JSON_THROW_ON_ERROR);
