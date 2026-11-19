<?php

namespace Mainstay\Redirects;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Mainstay\Content\Icon;
use Mainstay\Hook;
use Mainstay\Plugin;

/*
 | Redirects, kept in the admin and applied before routing: an entry whose
 | address changes is sent from the old one to the new, one that goes to the
 | trash answers 410 Gone (or goes where the owner chose) until it is
 | restored, the 404s visitors hit are counted for a redirect to be made from
 | them, and the owner's own redirects, exact or regex, answer beside those.
 | The screens are for whoever holds `use_redirects`.
 |
 | The automatic ones need an address for each entry, which the site plugin
 | gives. Without it nothing has an address, so none is made and the owner's
 | own redirects still work, which is why mainstay/site is required and not
 | checked for.
 */
#[Icon(file: __DIR__.'/../resources/icons/redirects.svg')]
class RedirectsPlugin extends Plugin
{
    public static function handle(): string
    {
        return 'redirects';
    }

    public static function label(): string
    {
        return 'Redirects';
    }

    public function views(): ?string
    {
        return __DIR__.'/../resources/views';
    }

    public function routes(): ?string
    {
        return __DIR__.'/../routes/admin.php';
    }

    public function settings(): array
    {
        return RedirectsSettings::declared();
    }

    public function migrations(): ?string
    {
        return __DIR__.'/../database/migrations';
    }

    public function tables(): array
    {
        return ['mainstay_redirects', 'mainstay_redirect_misses'];
    }

    /*
     | Before a write, where an entry is, which a before hook may only
     | remember; after it, what changed. Deleting for good needs no hook: the
     | entry was trashed first and its address is already answered.
     */
    public function hooks(): array
    {
        $watcher = fn (): EntryWatcher => app(EntryWatcher::class);

        return [
            Hook::filter('content.updating', function (array $data, string $type, ?int $id) use ($watcher) {
                $watcher()->remember($type, $id);

                return $data;
            }),
            Hook::action('content.publishing', fn (string $type, ?int $entry) => $watcher()->remember($type, $entry)),
            Hook::action('content.trashing', fn (string $type, int $id) => $watcher()->remember($type, $id)),
            ...array_map(
                fn (string $name) => Hook::action($name, fn (string $type, int $id) => $watcher()->changed($type, $id)),
                ['content.created', 'content.updated', 'content.published'],
            ),
            Hook::action('content.trashed', fn (string $type, int $id) => $watcher()->trashed($type, $id)),
            Hook::action('content.restored', fn (string $type, int $id) => $watcher()->restored($type, $id)),
        ];
    }

    public function boot(Application $app): void
    {
        $app->singleton(EntryWatcher::class);
        $app->register(RedirectsServiceProvider::class);
        $app->make(Kernel::class)->prependMiddleware(ApplyRedirects::class);
    }
}
