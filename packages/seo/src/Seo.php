<?php

namespace Mainstay\Seo;

use Attribute;
use InvalidArgumentException;
use Mainstay\Content\Media;
use Mainstay\Fields\Field;
use Mainstay\Media\Library;
use ReflectionProperty;

/*
 | An entry's search and social metadata, stored as JSON in a column of its
 | own and read back as a SeoMeta:
 |
 |     #[Seo]
 |     public ?SeoMeta $seo;
 |
 | A field like any other, so it is drafted, revised, translated (per
 | language unless `localized: false`) and published with the entry, and is in
 | the API's payload and schema. Core finds it by its attribute; nothing
 | registers it. Null is nothing set, so the property is nullable, as an
 | image's is.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Seo extends Field
{
    protected string $viewNamespace = 'seo';

    public function __construct(?bool $required = null, bool $localized = true, ?string $label = null)
    {
        parent::__construct($required, $localized, $label);
    }

    public function bind(ReflectionProperty $property): static
    {
        $this->stores($property, [SeoMeta::class], 'an SEO field stores a SeoMeta');

        if (! ($property->getType()?->allowsNull() ?? true)) {
            throw new InvalidArgumentException(sprintf(
                '%s::$%s is not nullable, and an SEO field holds null for nothing set. Declare it ?SeoMeta.',
                $property->getDeclaringClass()->getName(),
                $property->getName(),
            ));
        }

        return parent::bind($property);
    }

    public function column(): array
    {
        return ['json'];
    }

    public function label(): string
    {
        return $this->label ?? 'SEO';
    }

    public function rules(): array
    {
        return [...parent::rules(), 'array:'.implode(',', array_keys(SeoMeta::KEYS))];
    }

    /* The keys' own rules, under the field's: the allow-list above refuses
       a key that is not one, these the value of one that is. */
    public function rulesAt(string $attribute, mixed $value = null, bool $draft = false): array
    {
        $rules = parent::rulesAt($attribute, $value, $draft);

        foreach (SeoMeta::KEYS as $key => $rule) {
            $rules["{$attribute}.{$key}"] = match ($rule['type']) {
                'boolean' => ['nullable', 'boolean'],
                'integer' => ['nullable', 'integer', 'min:1', self::ID],
                default => ['nullable', 'string', "max:{$rule['max']}", ...($key === 'canonical' ? ['url:http,https'] : [])],
            };
        }

        return $rules;
    }

    public function complete(mixed $value, bool $stored = false): mixed
    {
        return $value instanceof SeoMeta ? $value->toArray() : $value;
    }

    public function references(mixed $value, string $at): array
    {
        $id = is_array($value) ? $value['social_image'] ?? null : ($value instanceof SeoMeta ? $value->socialImage?->id : null);
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? [] : ["{$at}.social_image" => [Media::class, $id, implode(',', Library::imageTypes())]];
    }

    /* The meta, with its image as the read loaded it, sized as the library's
       own preview: the one copy every upload has, since the original is
       never served. */
    public function cast(mixed $value, array $loaded = []): mixed
    {
        if ($this->blank($value)) {
            return null;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException("{$this->name} holds something that is not SEO data.");
        }

        $id = isset($value['social_image']) ? (int) $value['social_image'] : null;
        $image = $id === null ? null : ($loaded[Media::class][$id] ?? new Media($id))->sized(['social' => Library::preview()]);

        return SeoMeta::fromArray($value, $image);
    }

    /* Nothing set is null, so an entry with no SEO stores no JSON. */
    protected function to(mixed $value): mixed
    {
        $kept = $value instanceof SeoMeta ? $value->toArray() : SeoMeta::clean((array) $value);

        if (isset($kept['social_image'])) {
            $kept['social_image'] = (int) $kept['social_image'];
        }

        return $kept === [] ? null : $kept;
    }

    /* A component posts the inputs as an array, `seo[title]`, which needs no
       decoding. */
    public function fromForm(mixed $posted): mixed
    {
        return is_array($posted) ? SeoMeta::clean($posted) : null;
    }

    protected function json(): array
    {
        $properties = [];

        foreach (SeoMeta::KEYS as $key => $rule) {
            $properties[$key] = ['type' => $rule['type'], ...(isset($rule['max']) ? ['maxLength' => $rule['max']] : [])];
        }

        return ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
    }
}
