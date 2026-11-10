<?php

namespace Mainstay\Forms\Blocks;

use Mainstay\Content\Description;
use Mainstay\Content\Icon;

#[Icon('mail')]
#[Description('An email address.')]
class EmailInput extends FormField
{
    protected function typeRules(): array
    {
        return ['string', 'email', 'max:255'];
    }
}
