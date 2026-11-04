<?php

namespace Mainstay\Tests\Fixtures\Broken;

use Mainstay\Content\GlobalSet;
use Mainstay\Fields\Text;

/* A field called what the API names a payload's type by, refused on a
   global as on an entry. */
class Typed extends GlobalSet
{
    #[Text]
    public ?string $type;
}
