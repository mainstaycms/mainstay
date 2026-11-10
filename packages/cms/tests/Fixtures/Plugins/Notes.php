<?php

namespace Mainstay\Tests\Fixtures\Plugins;

use Mainstay\Admin\EntryTab;
use Mainstay\Admin\EntryTabContext;

/* A tab for a redirect's form only, with a toolbar and an aside. */
class Notes implements EntryTab
{
    public function handle(): string
    {
        return 'notes';
    }

    public function label(): string
    {
        return 'Notes';
    }

    public function applies(string $type): bool
    {
        return $type === SeoRedirect::class;
    }

    public function panel(): string
    {
        return 'seo::notes.panel';
    }

    public function data(EntryTabContext $context): array
    {
        return ['said' => $context->localized('/said'), 'for' => $context->type::handle()];
    }

    public function toolbar(): ?string
    {
        return 'seo::notes.toolbar';
    }

    public function aside(): ?string
    {
        return 'seo::notes.aside';
    }
}
