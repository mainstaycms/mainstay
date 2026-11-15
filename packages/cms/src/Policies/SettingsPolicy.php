<?php

namespace Mainstay\Policies;

use Illuminate\Auth\Access\Response;
use Mainstay\Auth\Capabilities;
use Mainstay\Auth\User;

/*
 | Who may change the site's settings, asked of every one the Settings screen
 | shows and writes: `manage_settings`, and the one more name the setting
 | itself asks for, if it does.
 */
class SettingsPolicy
{
    public function manage(?User $user, ?string $capability = null): Response
    {
        return Capabilities::check($user, 'Settings need a Mainstay user, and there is none.', [Capabilities::SETTINGS], ...($capability === null ? [] : [[$capability]]));
    }
}
