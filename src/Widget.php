<?php

declare(strict_types=1);

namespace Pollora\Debugbar;

/**
 * The ways a collector's data can be shown, all with php-debugbar's own widgets.
 */
enum Widget
{
    /** Name and value pairs; arrays and objects are dumped. */
    case Variables;

    /** Rows with the same columns. */
    case Table;

    /** SQL statements, with durations, duplicates and backtraces. */
    case Queries;
}
