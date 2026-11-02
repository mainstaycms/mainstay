<?php

namespace Mainstay\Api;

use Mainstay\Auth\Gate;
use Mainstay\Content\Document;
use Mainstay\Content\Entry;
use Mainstay\Content\Media;
use Mainstay\Fields\Field;
use Mainstay\Mainstay;

/*
 | The JSON Schema of Payload, as the caller may read it: what the API serves
 | at /schema and mainstay:typescript writes out, built from the declared
 | classes rather than written a second time. One definition per type the
 | caller may read, by its handle, and Mainstay's own -- an image, a
 | document's node -- capitalised, which no handle is. A field pointing at
 | a type the caller may not read is its stored reference alone, as the
 | payload hands it.
 */
final class Schema
{
    public const DIALECT = 'https://json-schema.org/draft/2020-12/schema';

    public function __construct(private Mainstay $mainstay) {}

    public function document(): array
    {
        $types = $this->readable();
        $ref = fn (string $class) => match (true) {
            $class === Media::class => ['$ref' => '#/$defs/Media'],
            $class === Document::class => ['$ref' => '#/$defs/Node'],
            in_array($class, $types, true) => ['$ref' => '#/$defs/'.$class::handle()],
            default => null,
        };

        $defs = [];

        foreach ($types as $type) {
            $internal = Gate::about($type)->allows('viewInternal', [$type, $type]);
            $fields = array_filter($this->mainstay->fields($type), fn (Field $field) => $internal || ! $field->internal);
            $properties = [
                'id' => ['type' => 'integer'],
                'type' => ['const' => $type::handle()],
                'locale' => ['type' => 'string'],
                ...(is_subclass_of($type, Entry::class) ? ['uri' => ['type' => ['string', 'null']]] : []),
                'createdAt' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                'updatedAt' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                ...array_map(fn (Field $field) => $field->payloadSchema($ref), $fields),
            ];

            $defs[$type::handle()] = [
                'type' => 'object',
                'title' => class_basename($type),
                'properties' => $properties,
                'required' => array_keys($properties),
                'additionalProperties' => false,
            ];
        }

        return ['$schema' => self::DIALECT, '$defs' => [...$defs, 'Media' => self::media(), 'Node' => self::node()]];
    }

    /** @return list<class-string> the registered types the caller may read */
    public function readable(): array
    {
        return array_values(array_filter($this->mainstay->registered(), fn (string $type) => Gate::about($type)->allows('viewAny', [$type, $type])));
    }

    /* An image as Media::toArray() hands it out. */
    private static function media(): array
    {
        $size = [
            'type' => 'object',
            'properties' => ['webp' => ['type' => 'string'], 'avif' => ['type' => 'string'], 'width' => ['type' => 'integer'], 'height' => ['type' => 'integer']],
            'required' => ['webp', 'avif', 'width', 'height'],
            'additionalProperties' => false,
        ];

        return [
            'type' => 'object',
            'title' => 'Media',
            'properties' => [
                'id' => ['type' => 'integer'],
                'alt' => ['type' => ['string', 'null']],
                'focal' => ['type' => ['array', 'null'], 'items' => ['type' => 'integer']],
                'sizes' => ['type' => 'object', 'additionalProperties' => $size],
            ],
            'required' => ['id', 'alt', 'focal', 'sizes'],
            'additionalProperties' => false,
        ];
    }

    /* A node of a document, the nodes it holds of the same shape. A site
       block's data is its fields' payloads, which its node does not
       describe: the block's own class does, in PHP. */
    private static function node(): array
    {
        return [
            'type' => 'object',
            'title' => 'Node',
            'properties' => [
                'type' => ['enum' => array_keys(Document::NODES)],
                'attrs' => ['type' => 'object'],
                'content' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/Node']],
                'marks' => ['type' => 'array', 'items' => ['type' => 'object']],
                'text' => ['type' => 'string'],
            ],
            'required' => ['type'],
        ];
    }
}
