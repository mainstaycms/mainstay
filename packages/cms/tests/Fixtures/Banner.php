<?php

namespace Mainstay\Tests\Fixtures;

use Mainstay\Content\Entry;
use Mainstay\Content\Media;
use Mainstay\Fields\Image;

/* Sizes wide enough to be written at a half and a quarter too. */
class Banner extends Entry
{
    #[Image(sizes: ['hero' => [600, 300], 'strip' => [320]])]
    public ?Media $image;
}
