<?php

namespace Mainstay\Forms\Blocks;

use Mainstay\Content\Description;
use Mainstay\Content\Icon;

#[Icon('block')]
#[Description('A box that is ticked or not. Required, it must be ticked.')]
class Checkbox extends FormField
{
    public function rules(): array
    {
        return $this->required ? ['accepted'] : ['nullable', 'boolean'];
    }

    public function answer(mixed $value): mixed
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
