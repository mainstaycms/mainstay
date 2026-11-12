<?php

namespace Mainstay\Seo;

use Mainstay\Content\Entry;
use Mainstay\Fields\Field;
use Mainstay\Mainstay;

/* Where a type keeps its SEO field, whatever the property is called. */
class SeoFields
{
    public function __construct(private Mainstay $mainstay) {}

    /** @param class-string $type */
    public function name(string $type): ?string
    {
        foreach ($this->mainstay->fields($type) as $name => $field) {
            if ($field instanceof Seo) {
                return $name;
            }
        }

        return null;
    }

    public function of(Entry $entry): ?SeoMeta
    {
        $name = $this->name($entry::class);

        if ($name === null || ! isset($entry->{$name})) {
            return null;
        }

        return $entry->{$name};
    }
}
