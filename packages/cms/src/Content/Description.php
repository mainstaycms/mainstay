<?php

namespace Mainstay\Content;

use Attribute;
use InvalidArgumentException;

/*
 | What a block is for, in a sentence, shown under its name in the Content
 | tab's Add block dialog: `#[Description('Selling points in a grid.')]`.
 |
 | Not inherited, as #[Route] is not: a subclass is a block of its own.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Description
{
    public function __construct(public readonly string $text)
    {
        if (trim($text) === '') {
            throw new InvalidArgumentException("#[Description] says what a block is for: #[Description('Selling points in a grid.')].");
        }
    }
}
