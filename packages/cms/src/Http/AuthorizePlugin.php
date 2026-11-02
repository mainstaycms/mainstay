<?php

namespace Mainstay\Http;

use Closure;
use Illuminate\Http\Request;
use Mainstay\Mainstay;
use Mainstay\Navigation;

/*
 | A plugin's screens, after the admin's sign-in, to someone the sidebar
 | offers them to and nobody else, whatever the plugin's own controllers
 | check. A screen someone may not use is not there, as the accounts' are
 | not, rather than refused.
 */
class AuthorizePlugin
{
    public function handle(Request $request, Closure $next, string $handle)
    {
        $plugin = app(Mainstay::class)->plugins()[$handle] ?? null;

        if ($plugin === null || ! Navigation::uses($plugin::class)) {
            return response()->view('mainstay::missing', status: 404);
        }

        return $next($request);
    }
}
