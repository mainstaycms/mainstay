<?php

namespace Mainstay\Policies;

use Illuminate\Auth\Access\Response;
use Mainstay\Auth\Capabilities;
use Mainstay\Auth\User;

/* Who may see the AI providers, save and remove their keys, test them and
   choose what each is used for: whoever holds `manage_connectors`, since a
   test spends the site's money and a key is the site's account. */
class ConnectorPolicy
{
    public function manage(?User $user): Response
    {
        return Capabilities::check($user, 'Managing AI connectors needs a Mainstay user, and there is none. Code that writes on its own authority passes overrideAccess: true.', [Capabilities::CONNECTORS]);
    }
}
