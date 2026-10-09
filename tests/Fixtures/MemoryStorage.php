<?php

declare(strict_types=1);

namespace Pollora\Debugbar\Tests\Fixtures;

use DebugBar\Storage\StorageInterface;

/**
 * Keeps stored requests in memory, so a test can read what the bar saved.
 */
final class MemoryStorage implements StorageInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $saved = [];

    public function save(string $id, array $data): void
    {
        $this->saved[$id] = $data;
    }

    public function get(string $id): array
    {
        return $this->saved[$id] ?? [];
    }

    public function find(array $filters = [], int $max = 20, int $offset = 0): array
    {
        return [];
    }

    public function clear(): void
    {
        $this->saved = [];
    }

    public function prune(int $hours = 24): void {}
}
