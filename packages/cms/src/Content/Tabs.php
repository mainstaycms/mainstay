<?php

namespace Mainstay\Content;

use Attribute;
use InvalidArgumentException;

/*
 | The order of the tabs on a type's entry form, by their ids: `#[Tabs('content',
 | 'details')]`. Those named come first in that order, and any other follows
 | alphabetically by its label. A type that says nothing has them all
 | alphabetical, Details among them, and opens on the first.
 |
 | Not inherited, as #[Route] is not: a subclass is a type of its own.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Tabs
{
    /** @var list<string> */
    public readonly array $first;

    public function __construct(string ...$first)
    {
        if ($first === []) {
            throw new InvalidArgumentException("#[Tabs] names the tabs to put first, by id: #[Tabs('content', 'details')].");
        }

        $this->first = array_values($first);
    }
}
