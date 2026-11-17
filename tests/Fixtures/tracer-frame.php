<?php

declare(strict_types=1);

/**
 * One frame of a trace, in a file of its own so that a Components map can give it an owner.
 */
function tracer_fixture_frame(callable $callback): mixed
{
    return $callback();
}
