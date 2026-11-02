<?php

namespace Mainstay\Tests\Fixtures\Api;

use Mainstay\Content\GlobalSet;
use Mainstay\Content\PublicRead;
use Mainstay\Fields\Text;

#[PublicRead]
class Colophon extends GlobalSet
{
    #[Text]
    public ?string $text;
}
