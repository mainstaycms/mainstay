<?php

namespace Mainstay\Admin;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Mainstay\Content\Tabs;
use ReflectionClass;

/* The tabs of an entry form as its bar draws them, which opens on the first. */
final class TabOrder
{
    /**
     * @param  class-string  $type
     * @param  array<string, string>  $labels  each tab's label by its id, Details included
     * @return array<string, string> the same, in the order the type asks for, the rest alphabetical
     */
    public static function of(string $type, array $labels): array
    {
        $first = ((new ReflectionClass($type))->getAttributes(Tabs::class)[0] ?? null)?->newInstance()->first ?? [];

        foreach ($first as $id) {
            if (! isset($labels[$id])) {
                throw new InvalidArgumentException("{$type} puts the tab '{$id}' first, and its form has none: its tabs are ".implode(', ', array_keys($labels)).'.');
            }
        }

        $ordered = [];

        foreach ($first as $id) {
            $ordered[$id] = $labels[$id];
        }

        $rest = array_diff_key($labels, $ordered);
        uasort($rest, fn (string $a, string $b) => Str::lower(Str::ascii($a)) <=> Str::lower(Str::ascii($b)));

        return $ordered + $rest;
    }
}
