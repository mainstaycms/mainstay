<?php

namespace Mainstay\Api;

use Mainstay\Content\Block;
use Mainstay\Content\ContentType;
use Mainstay\Content\Draft;
use Mainstay\Content\Entry;
use Mainstay\Fields\Date;
use Mainstay\Mainstay;
use ReflectionProperty;

/*
 | What the query layer reads, as the API hands it out: an entry or a global
 | as JSON, the same over REST and MCP, which Schema describes. Its own
 | properties first -- the id, the type's handle, the locale, the path and
 | the stamps in RFC 3339 -- then each field the read handed over, as its
 | payload(): one the reader may not see was never set, so it is not there.
 */
final class Payload
{
    public function __construct(private Mainstay $mainstay) {}

    public function of(ContentType $item): array
    {
        $payload = [
            /* None for an entry not published yet, which a draft reads. */
            ...(self::set($item, 'id') ? ['id' => $item->id] : []),
            'type' => $item::handle(),
            ...(self::set($item, 'locale') ? ['locale' => $item->locale] : []),
            ...($item instanceof Entry && self::set($item, 'uri') ? ['uri' => $item->uri] : []),
        ];

        foreach (['createdAt', 'updatedAt'] as $stamp) {
            if (self::set($item, $stamp)) {
                $payload[$stamp] = $item->{$stamp} === null ? null : Date::instant($item->{$stamp});
            }
        }

        return [...$payload, ...$this->fields($item)];
    }

    /* A draft, and the entry it would make live, with no id where that is
       not published yet. */
    public function draft(Draft $draft): array
    {
        return [
            'id' => $draft->id,
            'entryId' => $draft->entryId,
            'entry' => $this->of($draft->entry),
            'fields' => $draft->fields,
            'locales' => (object) $draft->locales,
            'updatedAt' => Date::instant($draft->updatedAt),
        ];
    }

    /** @return array<string, mixed> each field that is set, by name */
    private function fields(ContentType|Block $item): array
    {
        $fields = [];

        foreach ($this->mainstay->fields($item::class) as $name => $field) {
            if (self::set($item, $name)) {
                $fields[$name] = $field->payload($item->{$name}, $this->nested(...));
            }
        }

        return $fields;
    }

    /* What a field points at that the read loaded: an entry as its own
       payload, a block as its fields, its id and type being its node's. */
    private function nested(ContentType|Block $item): array
    {
        return $item instanceof Block ? $this->fields($item) : $this->of($item);
    }

    /* Whether the read set it: a property it left unset is absent, rather
       than null, and a reader may not be told what it holds. */
    private static function set(object $item, string $property): bool
    {
        return (new ReflectionProperty($item, $property))->isInitialized($item);
    }
}
