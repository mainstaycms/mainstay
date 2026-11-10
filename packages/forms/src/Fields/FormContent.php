<?php

namespace Mainstay\Forms\Fields;

use Attribute;
use Closure;
use Mainstay\Content\Document;
use Mainstay\Fields\Content;

/*
 | A form's content: Content, which also holds the form to a name for each of
 | its fields. A repeat is refused at the second block that has it, where
 | core reports a block's data: `content.content.4.attrs.data.name`.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class FormContent extends Content
{
    public function rulesAt(string $attribute, mixed $value = null, bool $draft = false): array
    {
        $rules = parent::rulesAt($attribute, $value, $draft);
        $tree = $value instanceof Document ? $value->toArray() : $value;
        $nodes = is_array($tree) && is_array($tree['content'] ?? null) && array_is_list($tree['content']) ? $tree['content'] : [];
        $seen = [];

        foreach ($nodes as $index => $node) {
            $name = is_array($node) && ($node['type'] ?? null) === 'block' ? $node['attrs']['data']['name'] ?? null : null;

            if (! is_string($name)) {
                continue;
            }

            if (isset($seen[$name])) {
                $rules["{$attribute}.content.{$index}.attrs.data.name"][] = fn (string $attribute, mixed $value, Closure $fail) => $fail("The form already has a field called {$name}.");
            }

            $seen[$name] = true;
        }

        return $rules;
    }

    public function component(): string
    {
        return 'mainstay::fields.content';
    }
}
