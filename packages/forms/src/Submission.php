<?php

namespace Mainstay\Forms;

use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Mainstay\Content\Entry;
use Mainstay\Fields\Relation;
use Mainstay\Fields\Text;
use Mainstay\Forms\Fields\Answers;

/*
 | What a visitor sent a form: kept as an entry, so the admin lists it and
 | the API reads it, and written by the public endpoints alone -- nobody makes
 | or changes one by hand, which SubmissionPolicy says.
 */
#[UsePolicy(SubmissionPolicy::class)]
class Submission extends Entry
{
    #[Relation(to: Form::class, required: true)]
    public ?Form $form;

    #[Answers]
    public array $answers;

    #[Text(required: true, max: 16)]
    public string $language;
}
