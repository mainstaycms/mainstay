<?php

namespace Mainstay\PageBuilder\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Mainstay\Auth\Gate;

class Visitor
{
    /*
     | `$draw` with nobody signed in, as the public page is drawn: Mainstay's
     | Gate reads as a visitor's, and the default guard is one with no user,
     | so a host view's `@auth`, `auth()` and `@can` see a guest rather than
     | the account the admin's session made the default. The container's
     | resolved guard is forgotten on the way in and out, so the rest of the
     | request keeps its own. In the form's locale.
     */
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
