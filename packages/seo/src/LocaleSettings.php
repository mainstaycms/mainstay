<?php

namespace Mainstay\Seo;

use Illuminate\Support\Facades\DB;
use Mainstay\Content\Media;
use Mainstay\Mainstay;
use Mainstay\Media\Library;

/*
 | The settings that differ by content locale: the title template and the
 | image a page with none of its own is shared with, kept in `seo_locales`.
 | Read once a request, for every locale, since a page asks for one and the
 | settings screen for all.
 */
class LocaleSettings
{
    /* `%title%` is the page's own, `%site%` the site's name. */
    public const DEFAULT_TEMPLATE = '%title% | %site%';

    public const TEMPLATE_MAX = 120;

    private const TABLE = 'seo_locales';

    /** @var array<string, object>|null */
    private ?array $rows = null;

    public function __construct(private Mainstay $mainstay) {}

    public function template(string $locale): string
    {
        $template = $this->row($locale)?->title_template;

        return $template === null || $template === '' ? self::DEFAULT_TEMPLATE : $template;
    }

    public function imageId(string $locale): ?int
    {
        $id = $this->row($locale)?->default_image;

        return $id === null ? null : (int) $id;
    }

    /* The image as the head prints it, or null where none is set or it is gone. */
    public function image(string $locale): ?Media
    {
        $id = $this->imageId($locale);
        $media = $id === null ? null : $this->mainstay->media()->find($id, overrideAccess: true);

        return $media === null || $media->missing ? null : $media->sized(['social' => Library::preview()]);
    }

    public function save(string $locale, ?string $template, ?int $image): void
    {
        $now = now();

        DB::table(self::TABLE)->upsert(
            [['locale' => $locale, 'title_template' => $template, 'default_image' => $image, 'created_at' => $now, 'updated_at' => $now]],
            ['locale'],
            ['title_template', 'default_image', 'updated_at'],
        );

        $this->rows = null;
    }

    private function row(string $locale): ?object
    {
        return ($this->rows ??= DB::table(self::TABLE)->get()->keyBy('locale')->all())[$locale] ?? null;
    }
}
