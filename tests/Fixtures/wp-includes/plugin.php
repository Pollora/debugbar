<?php

declare(strict_types=1);

/**
 * Stands in for WordPress's plugin API file: the tracer leaves out its frames.
 */
function tracer_fixture_apply(callable $callback): mixed
{
    return $callback();
}
