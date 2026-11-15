<?php

namespace Mainstay\Http;

use Closure;
use Illuminate\Http\Request;
use Mainstay\Navigation;

/*
 | A screen a plugin has made inside Settings, after the admin's sign-in, to
 | whoever may manage settings and nobody else: not there, as the accounts'
 | screens are not, rather than refused.
 */
class AuthorizeSettings
{
    public function handle(Request $request, Closure $next)
    {
        if (! Navigation::manages()) {
            return response()->view('mainstay::missing', status: 404);
        }

        return $next($request);
    }
}
