<?php

namespace Mainstay\Admin;

use Illuminate\Http\Request;
use Mainstay\Content\Draft;
use Mainstay\Fields\Field;

/* The form an entry tab is drawn for: which entry or draft, in which locale, with the values it holds. */
final readonly class EntryTabContext
{
    /**
     * @param  class-string  $type
     * @param  array<string, Field>  $fields
     * @param  array<string, mixed>  $values
     */
    public function __construct(
        public string $type,
        public ?int $id,
        public ?Draft $draft,
        public string $locale,
        public bool $defaultLocale,
        public Request $request,
        public array $fields,
        public array $values,
    ) {}

    /* A link that keeps the screen's locale, unless it is the default. */
    public function localized(string $url): string
    {
        return $this->defaultLocale ? $url : $url.(str_contains($url, '?') ? '&' : '?').'locale='.$this->locale;
    }
}
