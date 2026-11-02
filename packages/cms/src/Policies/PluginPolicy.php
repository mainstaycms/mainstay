<?php

namespace Mainstay\Policies;

use Illuminate\Auth\Access\Response;
use Mainstay\Auth\Capabilities;
use Mainstay\Auth\User;

/*
 | Whether a plugin's admin screens answer: to someone holding its
 | `use_<handle>`, and to nobody while it has no screens or is no longer
 | among the plugins config names -- a route cached before it left still
 | refuses.
 */
class PluginPolicy
{
    public function use(?User $user, string $plugin): Response
    {
        $name = Capabilities::plugins()[$plugin] ?? null;

        if ($name === null) {
            return Response::deny("{$plugin} is not a loaded plugin with screens.");
        }

        return Capabilities::check($user, "A plugin's screens need a Mainstay user, and there is none.", [$name]);
    }
}
