<?php

namespace Mainstay\Seo;

/* Heard when content is saved, published, trashed or restored: the sitemap is read again next time it is asked for. */
class ForgetSitemap
{
    public function __construct(private Sitemap $sitemap) {}

    public function handle(object $event): void
    {
        $this->sitemap->forget();
    }
}
