<?php

namespace Mainstay\Seo;

use Mainstay\Content\Media;

/*
 | What an entry tells a search engine and a social network about itself, as
 | its #[Seo] field holds it. Every part is optional: what is empty falls back
 | to the entry's own title and description, and to the site's defaults, when
 | the head is drawn.
 |
 | Stored as the array toArray() gives, with what is empty left out, so a
 | row holding nothing holds null.
 */
final readonly class SeoMeta
{
    /* Stored key => its rules, which the field's schema, its validation and
       the form's cleaning all read, so a key is named once. */
    public const KEYS = [
        'title' => ['type' => 'string', 'max' => 200],
        'description' => ['type' => 'string', 'max' => 500],
        'canonical' => ['type' => 'string', 'max' => 2048],
        'noindex' => ['type' => 'boolean'],
        'nofollow' => ['type' => 'boolean'],
        'social_title' => ['type' => 'string', 'max' => 200],
        'social_description' => ['type' => 'string', 'max' => 500],
        'social_image' => ['type' => 'integer'],
        'keyphrase' => ['type' => 'string', 'max' => 100],
    ];

    public function __construct(
        public ?string $title = null,
        public ?string $description = null,
        public ?string $canonical = null,
        public bool $noindex = false,
        public bool $nofollow = false,
        public ?string $socialTitle = null,
        public ?string $socialDescription = null,
        public ?Media $socialImage = null,
        public ?string $keyphrase = null,
    ) {}

    /* From the stored array, or one a form posted; `$image` is the Media the
       read loaded for social_image. */
    public static function fromArray(array $stored, ?Media $image = null): self
    {
        $stored = self::clean($stored);

        return new self(
            $stored['title'] ?? null,
            $stored['description'] ?? null,
            $stored['canonical'] ?? null,
            $stored['noindex'] ?? false,
            $stored['nofollow'] ?? false,
            $stored['social_title'] ?? null,
            $stored['social_description'] ?? null,
            $image,
            $stored['keyphrase'] ?? null,
        );
    }

    /*
     | What is set, by key: a blank string, an unticked box and no image are
     | the same as saying nothing, so the form's empty inputs store nothing.
     | A value of the wrong kind is kept as it is for the field's rules to
     | refuse.
     */
    public static function clean(array $values): array
    {
        $kept = [];

        foreach (self::KEYS as $key => $rule) {
            $value = $values[$key] ?? null;

            if ($rule['type'] === 'boolean' && ! is_array($value)) {
                $value = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $value;
            }

            if (is_string($value) && $rule['type'] !== 'boolean') {
                $value = trim($value);
            }

            if ($value !== null && $value !== '' && $value !== false) {
                $kept[$key] = $value;
            }
        }

        return $kept;
    }

    public function toArray(): array
    {
        return self::clean([
            'title' => $this->title,
            'description' => $this->description,
            'canonical' => $this->canonical,
            'noindex' => $this->noindex,
            'nofollow' => $this->nofollow,
            'social_title' => $this->socialTitle,
            'social_description' => $this->socialDescription,
            'social_image' => $this->socialImage?->id,
            'keyphrase' => $this->keyphrase,
        ]);
    }
}
