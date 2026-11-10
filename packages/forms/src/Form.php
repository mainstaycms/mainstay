<?php

namespace Mainstay\Forms;

use Mainstay\Content\Document;
use Mainstay\Content\Entry;
use Mainstay\Content\HoldsPage;
use Mainstay\Content\PublicRead;
use Mainstay\Fields\Blocks;
use Mainstay\Fields\Internal;
use Mainstay\Fields\RichText;
use Mainstay\Forms\Blocks\Checkbox;
use Mainstay\Forms\Blocks\EmailInput;
use Mainstay\Forms\Blocks\Select;
use Mainstay\Forms\Blocks\SubmitButton;
use Mainstay\Forms\Blocks\Textarea;
use Mainstay\Forms\Blocks\TextInput;
use Mainstay\Forms\Fields\FormContent;
use Mainstay\Forms\Fields\Recipients;
use Mainstay\Forms\Fields\Redirect;

/*
 | A form: an entry whose content is its fields, edited on the Content tab
 | from the blocks below, with text between them. It has no address of its own
 | -- a page prints it with `@form` or the Form block -- so it is edited as a
 | page, in the editor's frame.
 |
 | Readable without a token so a front end built elsewhere can draw it, except
 | for who is mailed. A submitter is sent on to `redirect` when it is set, and
 | otherwise shown `message`.
 */
#[PublicRead]
#[Blocks([TextInput::class, EmailInput::class, Textarea::class, Select::class, Checkbox::class, SubmitButton::class])]
class Form extends Entry implements HoldsPage
{
    #[FormContent(localized: true)]
    public ?Document $content;

    #[Internal]
    #[Recipients(label: 'Mailed to, separated by commas')]
    public ?string $recipients;

    #[RichText(localized: true, label: 'Confirmation message')]
    public ?Document $message;

    #[Redirect(localized: true, max: 2048, label: 'Send on to this address instead')]
    public ?string $redirect;

    public static function heading(): string
    {
        return 'Forms';
    }
}
