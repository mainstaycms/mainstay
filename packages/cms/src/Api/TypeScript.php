<?php

namespace Mainstay\Api;

use Illuminate\Support\Str;
use InvalidArgumentException;

/*
 | Schema's document as TypeScript declarations, for a front end built
 | outside PHP: an interface per definition, named as its class is. Only
 | what the document uses is read -- types, enum, const, anyOf, items,
 | properties, $ref -- and anything else is `unknown`, so a field type
 | written elsewhere with a schema of its own still declares.
 */
final class TypeScript
{
    public static function from(array $document): string
    {
        $names = [];

        foreach (array_keys($document['$defs']) as $key) {
            $name = ctype_upper($key[0]) ? $key : Str::studly($key);

            if (($other = array_search($name, $names, true)) !== false) {
                throw new InvalidArgumentException("The definitions {$other} and {$key} would both be declared as {$name}. Rename one of the types.");
            }

            $names[$key] = $name;
        }

        $declarations = array_map(
            fn (string $key, array $schema) => "export interface {$names[$key]} ".self::object($schema, $names, '')."\n",
            array_keys($document['$defs']),
            $document['$defs'],
        );

        return "// Written by `php artisan mainstay:typescript` from the API's /schema. Do not edit.\n\n".implode("\n", $declarations);
    }

    private static function type(array $schema, array $names, string $indent): string
    {
        return match (true) {
            isset($schema['$ref']) => $names[substr($schema['$ref'], strlen('#/$defs/'))] ?? 'unknown',
            isset($schema['anyOf']) => self::union(array_map(fn (array $one) => self::type($one, $names, $indent), $schema['anyOf'])),
            array_key_exists('const', $schema) => self::literal($schema['const']),
            isset($schema['enum']) => self::union(array_map(self::literal(...), $schema['enum'])),
            is_array($schema['type'] ?? null) => self::union(array_map(fn (string $type) => self::type([...$schema, 'type' => $type], $names, $indent), $schema['type'])),
            default => match ($schema['type'] ?? null) {
                'string' => 'string',
                'integer', 'number' => 'number',
                'boolean' => 'boolean',
                'null' => 'null',
                'array' => self::list(isset($schema['items']) ? self::type($schema['items'], $names, $indent) : 'unknown'),
                'object' => self::object($schema, $names, $indent),
                default => 'unknown',
            },
        };
    }

    private static function object(array $schema, array $names, string $indent): string
    {
        if (! isset($schema['properties'])) {
            return 'Record<string, '.(is_array($schema['additionalProperties'] ?? null) ? self::type($schema['additionalProperties'], $names, $indent) : 'unknown').'>';
        }

        $lines = [];

        foreach ((array) $schema['properties'] as $key => $property) {
            $optional = in_array($key, $schema['required'] ?? [], true) ? '' : '?';
            $quoted = preg_match('/\A[A-Za-z_$][A-Za-z0-9_$]*\z/', (string) $key) ? $key : self::literal((string) $key);
            $lines[] = "{$indent}    {$quoted}{$optional}: ".self::type($property, $names, "{$indent}    ").';';
        }

        return $lines === [] ? '{}' : "{\n".implode("\n", $lines)."\n{$indent}}";
    }

    private static function literal(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function union(array $types): string
    {
        return implode(' | ', array_values(array_unique($types)));
    }

    /* `(A | B)[]` rather than `A | B[]`, which is something else. */
    private static function list(string $type): string
    {
        return str_contains($type, ' ') || str_contains($type, "\n") ? "({$type})[]" : "{$type}[]";
    }
}
