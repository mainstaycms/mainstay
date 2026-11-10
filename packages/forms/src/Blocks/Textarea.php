<?php

namespace Mainstay\Forms\Blocks;

use Mainstay\Content\Description;
use Mainstay\Content\Icon;
use Mainstay\Fields\Number;

#[Icon('chat')]
#[Description('Several lines of text.')]
class Textarea extends FormField
{
    private const LONGEST = 5000;

    #[Number(min: 1, max: self::LONGEST)]
    public ?int $maxLength;

    protected function typeRules(): array
    {
        return ['string', 'max:'.($this->maxLength ?? self::LONGEST)];
    }
}
