<?php

namespace Mainstay\Parts\Tests\Fixtures;

use Mainstay\Content\Block;
use Mainstay\Fields\Text;

class Legal extends Block
{
    #[Text]
    public ?string $line;
}
