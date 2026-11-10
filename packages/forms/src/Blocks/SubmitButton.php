<?php

namespace Mainstay\Forms\Blocks;

use Mainstay\Content\Block;
use Mainstay\Content\Description;
use Mainstay\Content\Icon;
use Mainstay\Fields\Text;

#[Icon('button')]
#[Description('The button that sends the form.')]
class SubmitButton extends Block
{
    protected ?string $viewNamespace = 'forms';

    #[Text(required: true)]
    public string $label = 'Send';
}
