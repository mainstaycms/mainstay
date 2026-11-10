<?php

namespace Mainstay\Content;

/**
 | A type edited as a page though it has no route: its content is built from
 | blocks, as an entry type with a #[Route] builds one, and a plugin's tab
 | draws it. A global may be one, and so may an entry type with no #[Route] --
 | an entry's page is its route's, so it has a page of its own or none -- but
 | not a taxonomy. It has the `$content` an entry has, which its class names
 | blocks for with #[Blocks].
 |
 | @property Document|null $content
 */
interface HoldsPage
{
    /* The sidebar's heading over the types like it, apart from Globals. */
    public static function heading(): string;
}
