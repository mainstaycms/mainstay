<?php

namespace Mainstay;

/*
 | The hooks a plugin or a host hangs its code on, as WordPress has them. An
 | action is called at a point and returns nothing; a filter is handed a
 | value and returns it, changed or not. A callback that throws stops the
 | operation it hooks, and is refused as Mainstay's own refusals are when it
 | throws a ValidationException or an AuthorizationException.
 |
 | The names Mainstay calls are in the README. One list serves both kinds,
 | since a name is one or the other and never both.
 */
final class Hooks
{
    /** @var array<string, array<int, list<callable>>> by name, then priority */
    private array $callbacks = [];

    public function add(Hook $hook): void
    {
        $this->callbacks[$hook->name][$hook->priority][] = $hook->callback;
    }

    public function addAction(string $name, callable $callback, int $priority = Hook::DEFAULT_PRIORITY): void
    {
        $this->add(Hook::action($name, $callback, $priority));
    }

    public function addFilter(string $name, callable $callback, int $priority = Hook::DEFAULT_PRIORITY): void
    {
        $this->add(Hook::filter($name, $callback, $priority));
    }

    public function doAction(string $name, mixed ...$arguments): void
    {
        foreach ($this->inOrder($name) as $callback) {
            $callback(...$arguments);
        }
    }

    public function applyFilters(string $name, mixed $value, mixed ...$arguments): mixed
    {
        foreach ($this->inOrder($name) as $callback) {
            $value = $callback($value, ...$arguments);
        }

        return $value;
    }

    /** @return list<callable> */
    private function inOrder(string $name): array
    {
        $byPriority = $this->callbacks[$name] ?? [];
        ksort($byPriority);

        return array_merge(...array_values($byPriority));
    }
}
