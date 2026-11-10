<?php

namespace Mainstay\Parts;

use Mainstay\Content\Document;
use Mainstay\Content\GlobalSet;
use Mainstay\Content\HoldsPage;
use Mainstay\Fields\Content;

/*
 | A piece of the layout built from blocks, a navigation or a footer: the
 | blocks it allows are named on the class with #[Blocks([...])], as a type
 | names them. It is a global -- stored once per site, localized, drafted and
 | published as one is -- that holds content as an entry does and has no
 | address. A view prints it with `@part(Footer::class)`.
 */
abstract class Part extends GlobalSet implements HoldsPage
{
    #[Content(localized: true)]
    public ?Document $content;

    public static function heading(): string
    {
        return 'Parts';
    }
}
