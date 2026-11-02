<?php

namespace Mainstay\Tests\Fixtures\Plugins;

use Mainstay\Content\Entry;
use Mainstay\Fields\Text;

class SeoRedirect extends Entry
{
    #[Text(required: true)]
    public string $target;

    #[SeoMeta]
    public ?string $meta;
}
