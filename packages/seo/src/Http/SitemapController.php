<?php

namespace Mainstay\Seo\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Mainstay\Seo\Sitemap;

/* sitemap.xml: the pages of the host asked, with their translations as hreflang alternates. */
class SitemapController
{
    public function __invoke(Request $request, Sitemap $sitemap): Response
    {
        /* Written here: Blade would read the declaration's `<?` as PHP. */
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".view('seo::sitemap', ['pages' => $sitemap->pages($request->getHost())])->render();

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }
}
