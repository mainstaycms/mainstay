<?php

namespace Mainstay\Forms\Blocks;

use Mainstay\Content\Description;
use Mainstay\Content\Icon;
use Mainstay\Fields\Number;

#[Icon('paragraph')]
#[Description('A single line of text.')]
class TextInput extends FormField
{
    private const LONGEST = 255;

    #[Number(min: 1, max: self::LONGEST)]
    public ?int $maxLength;

    protected function typeRules(): array
    {
        return ['string', 'max:'.($this->maxLength ?? self::LONGEST)];
    }
}
