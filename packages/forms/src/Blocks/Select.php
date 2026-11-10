<?php

namespace Mainstay\Forms\Blocks;

use Illuminate\Validation\Rule;
use Mainstay\Content\Description;
use Mainstay\Content\Icon;
use Mainstay\Fields\Textarea as TextareaField;

#[Icon('list')]
#[Description('One choice from a list.')]
class Select extends FormField
{
    #[TextareaField(required: true, label: 'Options, one to a line')]
    public string $options;

    /** @return list<string> */
    public function choices(): array
    {
        return array_values(array_filter(array_map(trim(...), explode("\n", $this->options)), fn (string $option) => $option !== ''));
    }

    protected function typeRules(): array
    {
        return ['string', Rule::in($this->choices())];
    }
}
