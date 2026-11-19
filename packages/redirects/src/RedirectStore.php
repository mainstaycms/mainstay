<?php

namespace Mainstay\Redirects;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 | Every write to the redirects and the one read that is cached: the active
 | regex rules, which every request would otherwise load. Exact redirects are
 | one indexed read each and are not cached. The cache is one key, forgotten
 | after each write, which every cache driver can do: tags and locks are not
 | in all of them.
 */
class RedirectStore
{
    public const REGEX_CACHE_KEY = 'mainstay.redirects.regex';

    /** @return Collection<int, Redirect> in the order they are tried */
    public function regexRules(): Collection
    {
        $rows = Cache::rememberForever(self::REGEX_CACHE_KEY, fn () => Redirect::query()
            ->where('match_type', Redirect::REGEX)->where('enabled', true)
            ->orderBy('priority')->orderBy('id')->get()->toArray());

        return Redirect::hydrate($rows);
    }

    public function save(Redirect $redirect): Redirect
    {
        if (! $redirect->isRegex()) {
            $redirect->source = Address::normalise($redirect->source);
        }

        $redirect->source_hash = Address::hash($redirect->match_type, $redirect->source);
        $redirect->save();
        $this->flush();

        return $redirect;
    }

    /** @param list<int> $ids */
    public function delete(array $ids): int
    {
        return $this->flushing(Redirect::query()->whereKey($ids)->delete());
    }

    /** @param list<int> $ids */
    public function setEnabled(array $ids, bool $enabled): int
    {
        return $this->flushing(Redirect::query()->whereKey($ids)->update(['enabled' => $enabled]));
    }

    /* An address a page answers at again, or for the first time, is no longer
       one to redirect or to call gone. A redirect the owner made is theirs to
       keep. */
    public function removeAutomaticAt(string ...$addresses): int
    {
        $hashes = array_map(fn (string $address) => Address::hash(Redirect::EXACT, $address), $addresses);

        return $this->flushing(Redirect::query()->where('origin', '!=', Redirect::CUSTOM)->whereIn('source_hash', $hashes)->delete());
    }

    /** @return array<string, string> the sources of an entry's redirects of an origin, by locale */
    public function sourcesForEntry(string $type, int $id, string $origin): array
    {
        return Redirect::query()->where(['entry_type' => $type, 'entry_id' => $id, 'origin' => $origin])->pluck('source', 'locale')->all();
    }

    public function removeForEntry(string $type, int $id, string $origin): int
    {
        return $this->flushing(Redirect::query()->where(['entry_type' => $type, 'entry_id' => $id, 'origin' => $origin])->delete());
    }

    /* Every redirect whose target is the old address now points at the new
       one, so a visitor is never sent through an address that has itself
       moved. Query strings and fragments on a target are kept. */
    public function retarget(string $old, string $new): int
    {
        $moved = 0;
        /* LIKE only narrows the rows: its wildcards in a path match more than
           they should, and the comparison below decides. */
        $candidates = Redirect::query()->whereNotNull('target')->whereRaw('lower(target) like ?', ['%'.strtolower(ltrim($old, '/')).'%'])->get();

        foreach ($candidates as $redirect) {
            $path = strtok($redirect->target, '?#');
            $rest = substr($redirect->target, strlen($path));

            if ($redirect->target[0] === '/' && Address::normalise($path) === Address::normalise($old) && $redirect->source_hash !== Address::hash(Redirect::EXACT, $new)) {
                $redirect->update(['target' => $new.$rest]);
                $moved++;
            }
        }

        return $this->flushing($moved);
    }

    /** @param list<int> $ids */
    public function recordHits(array $ids): void
    {
        Redirect::query()->whereKey($ids)->update(['hits' => DB::raw('hits + 1'), 'last_hit_at' => now()]);
    }

    /* Shown in the admin until the rule is saved again. Forgotten from the
       cache, so the next request reads the message and does not write it
       again. */
    public function markFailing(Redirect $redirect, string $message): void
    {
        if ($redirect->last_error === $message) {
            return;
        }

        Redirect::query()->whereKey($redirect->id)->update(['last_error' => mb_substr($message, 0, 500)]);
        $this->flush();
    }

    public function flush(): void
    {
        Cache::forget(self::REGEX_CACHE_KEY);
    }

    private function flushing(int $affected): int
    {
        if ($affected > 0) {
            $this->flush();
        }

        return $affected;
    }
}
