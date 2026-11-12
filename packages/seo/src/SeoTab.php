<?php

namespace Mainstay\Seo;

use Mainstay\Admin\EntryTab;
use Mainstay\Admin\EntryTabContext;
use Mainstay\Content\GlobalSet;
use Mainstay\Fields\Image;
use Mainstay\Http\Form;
use Mainstay\Mainstay;

/*
 | The SEO tab of a type with a #[Seo] field: its inputs, which post inside
 | the entry's form as `seo[title]`, a snippet preview, and the live checks
 | the plugin's script runs on what the form holds. The field is drawn here
 | and not in Details, where it only points to this tab.
 */
class SeoTab implements EntryTab
{
    /* The field the page's text is in, which every entry type holds. */
    private const CONTENT_FIELD = 'content';

    public function __construct(private Mainstay $mainstay, private SeoFields $fields, private LocaleSettings $locales, private Locations $locations) {}

    public function handle(): string
    {
        return 'seo';
    }

    public function label(): string
    {
        return 'SEO';
    }

    public function applies(string $type): bool
    {
        return ! is_subclass_of($type, GlobalSet::class) && $this->fields->name($type) !== null;
    }

    public function panel(): string
    {
        return 'seo::tab';
    }

    public function toolbar(): ?string
    {
        return null;
    }

    public function aside(): ?string
    {
        return null;
    }

    /*
     | The values the panel draws, named `seo...` since an include shares the
     | form's own variables: where each input posts, the saved meta, where the
     | preview is asked, and what the script needs to write a title as the
     | head will -- the template, the site name, the host.
     */
    public function data(EntryTabContext $context): array
    {
        $type = $context->type;
        $handle = $type::handle();
        $name = $this->fields->name($type);
        $meta = $context->values[$name] ?? null;
        $endpoint = match (true) {
            $context->id !== null => route('mainstay.seo.preview.entry', [$handle, $context->id]),
            $context->draft !== null => route('mainstay.seo.preview.draft', [$handle, $context->draft->id]),
            default => route('mainstay.seo.preview.create', $handle),
        };

        return [
            'seoName' => $name,
            'seoMeta' => $meta instanceof SeoMeta ? $meta : null,
            'seoImage' => new Image(label: 'Social image', sizes: ['preview' => [640]]),
            'seoEndpoint' => $context->localized($endpoint),
            'seoLocale' => $context->locale,
            'seoTemplate' => $this->locales->template($context->locale),
            'seoSite' => (string) $this->mainstay->setting('seo.site_name', ''),
            'seoDescription' => (string) $this->mainstay->setting('seo.default_description', ''),
            'seoOrigin' => $this->locations->url($context->locale, '/'),
            'seoContent' => isset($context->fields[self::CONTENT_FIELD]) ? self::CONTENT_FIELD : null,
            'seoSlug' => Form::slug($type),
        ];
    }
}
