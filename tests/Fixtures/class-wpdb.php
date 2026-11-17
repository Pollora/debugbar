<?php

declare(strict_types=1);

/**
 * Stands in for WordPress's database file: the tracer leaves out its frames.
 */
function tracer_fixture_wpdb(callable $callback): mixed
{
    return $callback();
}
