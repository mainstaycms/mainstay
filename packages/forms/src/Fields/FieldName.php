<?php

namespace Mainstay\Forms\Fields;

use Attribute;
use Mainstay\Fields\Text;

/*
 | What a field is called in the form it is posted from: a lowercase word
 | with digits and underscores, which no other input of the page -- the
 | token, the method -- can share. A form holds each name once: see
 | FormContent.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class FieldName extends Text
{
    public function rules(): array
    {
        return [...parent::rules(), 'regex:/\A[a-z][a-z0-9_]*\z/'];
    }

    public function component(): string
    {
        return 'mainstay::fields.text';
    }
}
