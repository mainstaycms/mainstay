<?php

namespace Mainstay\Settings;

use Closure;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/*
 | One setting a plugin declares from `settings()`: what the site's owner is
 | asked, how it is drawn on the Settings screen and what an answer must be.
 | Its key is the plugin's own: stored and read as `<handle>.<key>`, so two
 | plugins can each have a `title`.
 |
 | `capability` is one more name the signed-in user must hold, beside
 | `manage_settings`, to see it and to write it; the roles grid lists it.
 */
final class Setting
{
    public const TYPES = ['text', 'textarea', 'boolean', 'number', 'select'];

    /**
     * @param  array<string, string>  $options  a select's values and their labels
     * @param  list<mixed>  $rules  Laravel validation rules, added to those its type brings
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type = 'text',
        public readonly string $description = '',
        public readonly mixed $default = null,
        public readonly array $rules = [],
        public readonly array $options = [],
        public readonly ?string $placeholder = null,
        public readonly ?string $capability = null,
    ) {
        if (preg_match('/\A[a-z][a-z0-9_]*\z/', $key) !== 1) {
            throw new InvalidArgumentException("A setting's key is lowercase letters, digits and underscores, starting with a letter, and \"{$key}\" is not.");
        }

        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("The setting {$key} has the type \"{$type}\". It is one of ".implode(', ', self::TYPES).'.');
        }

        if (($type === 'select') === ($options === [])) {
            throw new InvalidArgumentException("The setting {$key} is a select and has options, or neither: a select needs its options, and no other type takes any.");
        }
    }

    /** @return list<mixed> what an answer must satisfy */
    public function rules(): array
    {
        return [
            ...match ($this->type) {
                'boolean' => ['boolean'],
                'number' => ['nullable', 'numeric', fn (string $attribute, mixed $value, Closure $fail) => is_finite((float) $value) || $fail('The :attribute must be a finite number.')],
                'select' => [Rule::in(array_keys($this->options))],
                default => ['nullable', 'string'],
            },
            ...$this->rules,
        ];
    }

    /* A browser's line ends are CRLF, which is two characters to a length
       rule and not part of the text. */
    public static function unix(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    /* A validated answer as it is stored: a blank number is none at all. */
    public function fromForm(mixed $posted): mixed
    {
        return match ($this->type) {
            'boolean' => filter_var($posted, FILTER_VALIDATE_BOOL),
            'number' => $posted === null || $posted === '' ? null : $posted + 0,
            default => self::unix((string) $posted),
        };
    }
}
