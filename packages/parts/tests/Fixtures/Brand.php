<?php

namespace Mainstay\Parts\Tests\Fixtures;

use Mainstay\Content\Block;
use Mainstay\Content\Media;
use Mainstay\Fields\Image;
use Mainstay\Fields\Text;

/* A name with a logo beside it. */
class Brand extends Block
{
    #[Text(required: true)]
    public string $name;

    #[Image(sizes: ['thumb' => [16, 16]])]
    public ?Media $logo;
}
