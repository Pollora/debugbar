<?php

declare(strict_types=1);

namespace Pollora\Debugbar\Recording;

/**
 * A hook callback that reports how long it ran, put in place of the original
 * in `$wp_filter` while hook timings are on.
 *
 * It reads like the original where code looks inside `$wp_filter`: an array
 * callback's class and method stay reachable as `[0]` and `[1]`, so helpers
 * that find a callback by its class keep working.
 *
 * @implements \ArrayAccess<int, mixed>
 */
final class TimedCallback implements \ArrayAccess
{
    public function __construct(
        public readonly mixed $callback,
        private readonly HookTimer $timer,
        private readonly string $key,
    ) {}

    public function __invoke(mixed ...$args): mixed
    {
        $this->timer->enter();

        try {
            return ($this->callback)(...$args);
        } finally {
            $this->timer->leave($this->key);
        }
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_array($this->callback) && isset($this->callback[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return is_array($this->callback) ? ($this->callback[$offset] ?? null) : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('A timed hook callback cannot be changed.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('A timed hook callback cannot be changed.');
    }
}
