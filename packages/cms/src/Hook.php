<?php

namespace Mainstay;

/*
 | One registration in `Plugin::hooks()`: the hook's name, the callable that
 | answers it, and its priority, the lower running first and registration
 | order breaking a tie. An action's return is ignored; a filter's is the
 | value handed on.
 */
final class Hook
{
    public const DEFAULT_PRIORITY = 10;

    /** @param  callable  $callback */
    private function __construct(public readonly string $name, public readonly mixed $callback, public readonly int $priority) {}

    public static function action(string $name, callable $callback, int $priority = self::DEFAULT_PRIORITY): self
    {
        return new self($name, $callback, $priority);
    }

    public static function filter(string $name, callable $callback, int $priority = self::DEFAULT_PRIORITY): self
    {
        return new self($name, $callback, $priority);
    }
}
