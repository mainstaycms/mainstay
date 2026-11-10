<?php

namespace Mainstay\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Mainstay\Admin\Widget;
use Mainstay\Auth\Gate;
use Mainstay\Mainstay;

/*
 | The admin's front door: the widgets each user has switched on, and Screen
 | options, where they switch them. Stored per account as a map of handle to
 | yes or no, so a widget added later is on or off by its own default rather
 | than by what the account chose before it existed.
 */
class DashboardController
{
    /* Where the account's choice is kept, in its preferences. */
    private const PREFERENCE = 'dashboard.widgets';

    public function __construct(private Mainstay $mainstay) {}

    public function show(): View
    {
        $user = Gate::user();
        $available = $this->mainstay->dashboardWidgets($user);
        $chosen = $user->preference(self::PREFERENCE, []);

        return view('mainstay::dashboard', [
            'available' => $available,
            'shown' => array_values(array_filter($available, fn (Widget $widget) => $chosen[$widget::handle()] ?? $widget->enabledByDefault())),
            'user' => $user,
        ]);
    }

    /* Each widget the user may have is on when ticked and off when not, so a
       handle that is posted for any other is never stored. */
    public function update(Request $request): RedirectResponse
    {
        $user = Gate::user();
        $ticked = $request->array('widgets');
        $chosen = $user->preference(self::PREFERENCE, []);

        foreach ($this->mainstay->dashboardWidgets($user) as $widget) {
            $chosen[$widget::handle()] = in_array($widget::handle(), $ticked, true);
        }

        $user->setPreference(self::PREFERENCE, $chosen);

        return redirect()->route('mainstay.admin')->with('status', 'Screen options saved.');
    }
}
