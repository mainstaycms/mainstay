<?php

namespace Mainstay;

use Illuminate\Contracts\Foundation\Application;
use Mainstay\Admin\EntryTab;
use Mainstay\Admin\Widget;
use Mainstay\Content\ContentType;

/*
 | A package's types, views, admin screens, dashboard widgets, listeners, migrations and boot step, which
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

    /** @return list<class-string<EntryTab>|EntryTab> the tabs it adds to an entry's form, beside Details */
    public function entryTabs(): array
    {
        return [];
    }

    /** @return list<class-string<Widget>> the cards it adds to the dashboard, each one the user may switch off in Screen options */
    public function widgets(): array
    {
        return [];
    }

    /*
     | Scripts and stylesheets the admin loads after its own, as paths in the
     | directory the host publishes the plugin's files to, `public/vendor/<handle>`.
     |
     | @return list<string>
     */
    public function assets(): array
    {
        return [];
    }

    /** @return array<class-string, list<class-string|callable>> each event it listens to and its listeners, as Laravel's `$listen` */
    public function listeners(): array
    {
        return [];
    }

    /* The directory its migrations are in, run with the package's own. */
    public function migrations(): ?string
    {
        return null;
    }

    /** @return list<string> the tables its migrations make, which no content type may be stored in */
    public function tables(): array
    {
        return [];
    }

    /*
     | What Laravel keeps no declarative home for: a service provider to
     | register, a container callback, a Blade directive, a route outside the
     | admin. Run once every plugin is loaded, before the admin's routes.
     */
    public function boot(Application $app): void {}
}
