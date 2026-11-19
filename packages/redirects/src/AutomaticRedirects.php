<?php

namespace Mainstay\Redirects;

/*
 | The redirects the plugin makes as entries change: a moved address to its
 | new one, a trashed entry's address to 410 or to the owner's chosen page.
 | A redirect the owner made at an address is never replaced: an automatic
 | one is made in the gaps around it.
 */
class AutomaticRedirects
{
    public function __construct(private RedirectStore $store, private RedirectsSettings $settings) {}

    /**
     * @param  array<string, string>  $old  the entry's addresses before, by locale
     * @param  array<string, string>  $new  and after
     */
    public function moved(string $type, int $id, array $old, array $new): void
    {
        $this->store->removeAutomaticAt(...array_values($new));

        foreach ($old as $locale => $before) {
            $after = $new[$locale] ?? null;

            if ($after === null || Address::normalise($before) === Address::normalise($after)) {
                continue;
            }

            $this->store->retarget($before, $after);
            $this->register($type, $id, $locale, Redirect::MOVED, $before, $after, Redirect::DEFAULT_STATUS);
        }
    }

    /** @param  array<string, string>  $addresses */
    public function trashed(string $type, int $id, array $addresses): void
    {
        $target = $this->settings->trashedTarget();

        foreach ($addresses as $locale => $address) {
            $this->register($type, $id, $locale, Redirect::TRASHED, $address, $target, $target === null ? Redirect::GONE : Redirect::DEFAULT_STATUS);
        }
    }

    /* Out of the trash at the addresses it was trashed from, or at others if
       a clash moved its slug: those are redirected to the new ones. */
    public function restored(string $type, int $id, array $addresses): void
    {
        $trashedAt = $this->store->sourcesForEntry($type, $id, Redirect::TRASHED);
        $this->store->removeForEntry($type, $id, Redirect::TRASHED);

        $this->moved($type, $id, $trashedAt, $addresses);
    }

    private function register(string $type, int $id, string $locale, string $origin, string $source, ?string $target, int $status): void
    {
        $existing = Redirect::query()->where('source_hash', Address::hash(Redirect::EXACT, $source))->first();

        if ($existing?->origin === Redirect::CUSTOM) {
            return;
        }

        $this->store->save(($existing ?? new Redirect)->fill([
            'source' => Address::normalise($source),
            'match_type' => Redirect::EXACT,
            'target' => $target,
            'status' => $status,
            'origin' => $origin,
            'entry_type' => $type,
            'entry_id' => $id,
            'locale' => $locale,
            'preserve_query' => true,
            'enabled' => true,
            'last_error' => null,
        ]));
    }
}
