<?php

declare(strict_types=1);

namespace Pollora\Debugbar\Contracts;

/**
 * Adds a section of name => value pairs to an existing tab.
 *
 * For data that belongs beside Pollora's or WordPress's own rather than in a
 * tab of its own — a shop's cart next to the request, say. Register it with
 * the `pollora.debugbar.sections` container tag.
 */
interface SectionProvider
{
    /**
     * The collector name of the tab to add to: `pollora`, `wp_request`…
     */
    public function tab(): string;

    /**
     * The section's title, shown before each of its names.
     */
    public function title(): string;

    /**
     * @return array<string, mixed>
     */
    public function values(): array;
}
