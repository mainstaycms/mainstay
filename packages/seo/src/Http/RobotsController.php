<?php

namespace Mainstay\Seo\Http;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Mainstay\Mainstay;

/*
 | robots.txt: the admin kept out of the index, the whole site when its owner
 | asked for that, and where this host's sitemap is.
 */
class RobotsController
{
    public function __invoke(Mainstay $mainstay): Response
    {
        $lines = ['User-agent: *'];

        if ($mainstay->setting('seo.noindex_site')) {
            $lines[] = 'Disallow: /';
        } else {
            $lines[] = config('mainstay.domain') === null ? 'Disallow: /'.trim((string) config('mainstay.path', 'admin'), '/').'/' : 'Disallow:';
            $lines[] = '';
            $lines[] = 'Sitemap: '.URL::route('mainstay.seo.sitemap');
        }

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
