<?php

namespace Mainstay\Forms\Blocks;

use Mainstay\Content\Block;
use Mainstay\Fields\Boolean;
use Mainstay\Fields\Text;
use Mainstay\Forms\Fields\FieldName;
use Mainstay\Forms\Forms;

/*
 | A block of a form that takes an answer. It names the rules the answer is
 | held to, so the form's rules are the blocks' own and nothing else says
 | what a field accepts.
 */
abstract class FormField extends Block
{
    protected ?string $viewNamespace = 'forms';

    #[FieldName(required: true, max: 64)]
    public string $name;

    #[Text(required: true)]
    public string $label;

    #[Boolean]
    public bool $required = false;

    /** @return list<mixed> Laravel rules for the answer, presence included */
    public function rules(): array
    {
        return [$this->required ? 'required' : 'nullable', ...$this->typeRules()];
    }

    /* The answer as it is kept: a checkbox's is true or false, the others' as given. */
    public function answer(mixed $value): mixed
    {
        return $value;
    }

    /** @return list<mixed> */
    protected function typeRules(): array
    {
        return [];
    }

    /* What the submitter typed, when this form was the one that came back refused. */
    public function typed(): mixed
    {
        return app(Forms::class)->typed($this->name);
    }

    public function error(): ?string
    {
        return app(Forms::class)->error($this->name);
    }
}
