<?php

namespace Mainstay\Forms\Fields;

use Attribute;
use Closure;
use Mainstay\Fields\Text;

/* The addresses a form's submissions are mailed to, separated by commas. */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Recipients extends Text
{
    public function rules(): array
    {
        return [...parent::rules(), function (string $attribute, mixed $value, Closure $fail) {
            $invalid = array_filter(self::split((string) $value), fn (string $address) => filter_var($address, FILTER_VALIDATE_EMAIL) === false);

            if ($invalid !== []) {
                $fail('The :attribute field holds '.implode(', ', $invalid).', which '.(count($invalid) === 1 ? 'is not an email address' : 'are not email addresses').'.');
            }
        }];
    }

    public function component(): string
    {
        return 'mainstay::fields.text';
    }

    /** @return list<string> */
    public static function split(?string $recipients): array
    {
        return array_values(array_filter(array_map(trim(...), explode(',', (string) $recipients)), fn (string $address) => $address !== ''));
    }
}
