<?php

namespace Mainstay\Forms\Http;

use Mainstay\Forms\Form;

/*
 | What a form says to someone it accepted: sent on to an address, or shown a
 | message -- the form's own, or a plain thanks.
 */
class Confirmation
{
    private const THANKS = 'Thank you. Your message has been sent.';

    public static function redirect(Form $form): ?string
    {
        return filled($form->redirect ?? null) ? $form->redirect : null;
    }

    public static function message(Form $form): string
    {
        return $form->message?->toHtml() ?? '<p>'.self::THANKS.'</p>';
    }
}
