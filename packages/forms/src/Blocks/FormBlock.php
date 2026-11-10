<?php

namespace Mainstay\Forms\Blocks;

use Mainstay\Content\Block;
use Mainstay\Content\Description;
use Mainstay\Content\Icon;
use Mainstay\Fields\Relation;
use Mainstay\Forms\Form;

/* A form put on a page, chosen from the forms there are. */
#[Icon('mail')]
#[Description('A form from the forms you have made.')]
class FormBlock extends Block
{
    protected ?string $viewNamespace = 'forms';

    #[Relation(to: Form::class, required: true)]
    public ?Form $form;

    public static function handle(): string
    {
        return 'form';
    }
}
