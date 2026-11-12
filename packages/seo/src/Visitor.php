<?php

namespace Mainstay\Seo;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Mainstay\Auth\Gate;

/*
 | Code run as a visitor, with nobody signed in, so that what a page, a
 | sitemap or a preview shows is what the public sees and not what the
 | account asking may. The same switch the page builder makes; it is not
 | imported, as this plugin works without it.
 */
class Visitor
{
    public function draw(Request $request, string $locale, callable $draw): mixed
    {
        $user = $request->attributes->get(Gate::USER);
        $guard = Auth::getDefaultDriver();
        $language = App::getLocale();

        $request->attributes->remove(Gate::USER);
        Auth::shouldUse(Gate::VISITOR);
        app()->forgetInstance('auth.driver');
        App::setLocale($locale);

        try {
            return $draw();
        } finally {
            $request->attributes->set(Gate::USER, $user);
            Auth::shouldUse($guard);
            app()->forgetInstance('auth.driver');
            App::setLocale($language);
        }
    }
}
