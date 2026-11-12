<?php

namespace Mainstay\Http;

use Closure;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Auth;
use Mainstay\Auth\Gate;
use Symfony\Component\HttpFoundation\Response;

/*
 | Who is asking for a media file, when an editor might be: the admin's
 | session is read only for a request that carries its cookie. Every other
 | request -- every visitor's -- starts no session and sets no cookie, so a
 | public file stays cacheable by anything between the site and the browser.
 | An editor's request for one gets the file without the cookie back.
 */
class EditorSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->cookies->has(StartSession::COOKIE)) {
            return $next($request);
        }

        $response = (new Pipeline(app()))->send($request)->through([
            EncryptCookies::class,
            StartSession::class,
            function (Request $request, Closure $next) {
                $request->attributes->set(Gate::USER, Auth::guard('mainstay')->user());

                return $next($request);
            },
        ])->then($next);

        /* A public file is cached by what sits between the site and the
           browser, which must never hold an editor's session cookie. */
        if ($response->headers->hasCacheControlDirective('public')) {
            foreach ($response->headers->getCookies() as $cookie) {
                $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
            }
        }

        return $response;
    }
}
