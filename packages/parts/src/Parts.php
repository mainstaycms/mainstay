<?php

namespace Mainstay\Parts;

use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use Mainstay\Mainstay;

class Parts
{
    public function __construct(private Mainstay $mainstay) {}

    /*
     | A part as the site prints it, in the request's locale: its blocks in
     | their order, each drawn by its view, and its text as a Document draws
     | it. Nothing where the part has not been published in that language.
     |
     | @param  class-string<Part>  $part
     */
    public function print(string $part): HtmlString
    {
        if (! is_subclass_of($part, Part::class)) {
            throw new InvalidArgumentException("{$part} is not a part: print one with a class that extends ".Part::class.'.');
        }

        /** @var Part|null $global */
        $global = $this->mainstay->global($part);

        return new HtmlString($global?->content?->toHtml() ?? '');
    }
}
