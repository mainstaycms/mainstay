<?php

namespace Mainstay\Tests\Fixtures;

use Mainstay\Content\Entry;
use Mainstay\Content\Icon;
use Mainstay\Fields\Text;

#[Icon('newspaper')]
class Iconed extends Entry
{
    #[Text]
    public string $title;
}
