<?php

namespace Mainstay\Forms;

use Mainstay\Forms\Blocks\FormField;

/*
 | What a form accepts, read off the blocks its content holds: each field's
 | own rules under its name. The one place both endpoints ask, so a web post
 | and a JSON post are held to the same.
 */
class FormRules
{
    /** @return array<string, list<mixed>> */
    public static function for(Form $form): array
    {
        $rules = [];

        foreach (self::fields($form) as $field) {
            $rules[$field->name] = $field->rules();
        }

        return $rules;
    }

    /** @return list<FormField> the blocks of the content that take an answer, in their order */
    public static function fields(Form $form): array
    {
        return array_values(array_filter($form->content?->blocks() ?? [], fn (object $block) => $block instanceof FormField));
    }
}
