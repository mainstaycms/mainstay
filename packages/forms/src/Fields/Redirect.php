<?php

namespace Mainstay\Forms\Fields;

use Attribute;
use Mainstay\Fields\Text;

/* A web address a submitter is sent on to. */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Redirect extends Text
{
    public function rules(): array
    {
        return [...parent::rules(), 'url:http,https'];
    }

    public function component(): string
    {
        return 'mainstay::fields.text';
    }
}
