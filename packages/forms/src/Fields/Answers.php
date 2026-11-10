<?php

namespace Mainstay\Forms\Fields;

use Attribute;
use Mainstay\Fields\Field;
use ReflectionProperty;

/*
 | What a submission holds, the answers as they were given: a list of
 | `{name, label, value}`, the label as the form had it then, so a form
 | changed later does not change what an old submission says. Kept in a json
 | column; core has no plain json field.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Answers extends Field
{
    protected string $viewNamespace = 'forms';

    public function bind(ReflectionProperty $property): static
    {
        $this->stores($property, ['array'], 'answers are a list');

        return parent::bind($property);
    }

    public function column(): array
    {
        return ['json'];
    }

    protected function empty(): mixed
    {
        return [];
    }

    /* Present rather than required: a form of nothing but a button has no answers, and an empty list is one. */
    public function rules(): array
    {
        return ['present', 'array', 'list'];
    }

    protected function json(): array
    {
        return [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string'], 'label' => ['type' => 'string']],
                'required' => ['name', 'label', 'value'],
            ],
        ];
    }
}
