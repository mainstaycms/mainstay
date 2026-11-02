<?php

namespace Mainstay\Tests\Fixtures\Plugins;

use Mainstay\Content\Block;
use Mainstay\Fields\Text;

class SeoFaq extends Block
{
    protected ?string $viewNamespace = 'seo';

    #[Text]
    public ?string $question;
}
