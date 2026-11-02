<?php

namespace Mainstay\Tests\Fixtures\Plugins;

use Mainstay\Content\GlobalSet;
use Mainstay\Fields\Text;

class SeoSettings extends GlobalSet
{
    #[Text]
    public ?string $siteName;
}
