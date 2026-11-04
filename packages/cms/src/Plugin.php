<?php

namespace Mainstay;

use Mainstay\Content\ContentType;

/*
 | A package's types, views, admin screens, listeners and AI providers, which
 | Mainstay wires in when `config('mainstay.plugins')` names the class and at
 | no other time: nothing is discovered, and nothing is switched on from the
 | admin. A plugin is the host's own code once installed, so this is what it
 | declares rather than a fence around what it can do.
 |
 | `#[Icon]` on the class is its item in the admin's sidebar, when it has
 | screens.
 */
abstract class Plugin
{
    /*
     | Its views' namespace, its screens' segment and its capability's noun,
     | `use_<handle>`. Named by its author rather than derived from the class:
     | a plugin's class is often called `Plugin`, and renaming a class must not
     | rename what the roles hold.
     */
    abstract public static function handle(): string;

    /** @return list<class-string<ContentType>> its types, registered as the host's are */
    public function types(): array
    {
        return [];
    }

    /* The directory its views are in, under its handle: its blocks' at
       `blocks/`, its field types' at `components/fields/`, its screens'. A
       host overrides one at `resources/views/vendor/<handle>`. */
    public function views(): ?string
    {
        return null;
    }

    /*
     | A routes file of its admin screens, loaded under `/<handle>` inside the
     | admin's sign-in and CSRF check, named `mainstay.plugins.<handle>.*`, and
     | answered only to someone holding `use_<handle>`. The one named `index` is
     | its sidebar item.
     */
    public function routes(): ?string
    {
        return null;
    }

    /** @return array<class-string, list<class-string|callable>> each event it listens to and its listeners, as Laravel's `$listen` */
    public function listeners(): array
    {
        return [];
    }

    /*
     | AI providers for the Connectors screen, as entries of laravel/ai's
     | `ai.providers` by name, with a `label` to list them by:
     | `['together' => ['label' => 'Together AI', 'driver' => 'openai-compatible',
     | 'url' => 'https://api.together.xyz/v1']]`. A driver laravel/ai lacks is a
     | closure taking the app and the provider's config and returning a
     | laravel/ai Provider. The host's own `ai.providers` entry of the name
     | wins key by key, so its config gives one a key from its env.
     |
     | @return array<string, array<string, mixed>>
     */
    public function providers(): array
    {
        return [];
    }
}
