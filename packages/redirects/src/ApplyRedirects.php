<?php

namespace Mainstay\Redirects;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/*
 | The first thing a request meets, before routing: a redirect for its
 | address answers it, and a 404 that comes back is counted. A global
 | middleware rather than one of the web group, which only runs for a request
 | some route has matched, and a site without a catch-all would never see the
 | old address at all.
 |
 | It leaves alone what is not a page a visitor asked for: anything but GET
 | and HEAD, the admin, the API, and the files the web server hands out.
 */
class ApplyRedirects
{
    /* Where the application's own files are served from, which a rule's
       pattern of `.*` must not catch. */
    private const FILE_PREFIXES = ['vendor', 'storage', 'build'];

    private const FILE_EXTENSIONS = ['css', 'js', 'map', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'mp4', 'webm', 'mp3', 'json', 'xml', 'txt'];

    private const ROBOT = '/bot|crawl|spider|slurp/i';

    public function __construct(private Resolver $resolver, private MissLog $misses, private RedirectsSettings $settings, private RedirectStore $store) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isPage($request)) {
            return $next($request);
        }

        try {
            $resolution = $this->resolver->resolve($request->getPathInfo(), reportFailures: true);
        } catch (RedirectLoop) {
            /* Already written on the rule for the admin; the page itself is
               the better answer than a circle. */
            return $next($request);
        }

        if ($resolution === null) {
            return $next($request);
        }

        $this->store->recordHits($resolution->ruleIds());

        if ($resolution->isGone()) {
            return response("Gone\n", Redirect::GONE, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return redirect($resolution->location($request->getQueryString()), $resolution->status);
    }

    /* After the response is sent, so counting a 404 never slows it. */
    public function terminate(Request $request, Response $response): void
    {
        if ($response->getStatusCode() === 404 && $request->isMethod('GET') && $this->isPage($request) && ! $this->isFileOrRobot($request) && $this->settings->recordMisses()) {
            $this->misses->record($request->getPathInfo(), $request->headers->get('referer'));
        }
    }

    private function isPage(Request $request): bool
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return false;
        }

        $reserved = array_filter([config('mainstay.path'), config('mainstay.api.prefix'), ...self::FILE_PREFIXES]);

        foreach ($reserved as $prefix) {
            if ($request->is($prefix, "{$prefix}/*")) {
                return false;
            }
        }

        return true;
    }

    private function isFileOrRobot(Request $request): bool
    {
        $extension = strtolower(pathinfo($request->getPathInfo(), PATHINFO_EXTENSION));

        return in_array($extension, self::FILE_EXTENSIONS, true) || preg_match(self::ROBOT, (string) $request->userAgent()) === 1;
    }
}
