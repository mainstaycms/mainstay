<?php

namespace Mainstay\Redirects;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/*
 | The addresses visitors asked for and found nothing at, counted per
 | address. It is bounded twice: an address is only added while the table is
 | under its cap, so a scanner trying a million paths cannot fill the disk
 | between prunes, and prune() drops what has not been seen for the retention
 | period and then the least recent beyond the cap.
 */
class MissLog
{
    private const REFERRER_MAX = 500;

    public function __construct(private RedirectsSettings $settings) {}

    public function record(string $path, ?string $referrer): void
    {
        $hash = Address::hash(Redirect::EXACT, $path);
        /* A direct visit does not wipe the last page that linked here. */
        $seen = ['last_seen_at' => now(), ...($referrer === null ? [] : ['referrer' => mb_substr($referrer, 0, self::REFERRER_MAX)])];

        if ($this->countAgain($hash, $seen) > 0 || Miss::query()->count() >= $this->settings->maxMisses()) {
            return;
        }

        try {
            Miss::query()->create(['address' => mb_substr(Address::normalise($path), 0, 500), 'address_hash' => $hash, 'first_seen_at' => now(), ...$seen]);
        } catch (UniqueConstraintViolationException) {
            /* Another request recorded the same address first. */
            $this->countAgain($hash, $seen);
        }
    }

    /* Drops the miss for an address that now has a redirect. */
    public function forget(string $path): void
    {
        Miss::query()->where('address_hash', Address::hash(Redirect::EXACT, $path))->delete();
    }

    public function prune(): int
    {
        $pruned = Miss::query()->where('last_seen_at', '<', now()->subDays($this->settings->keepDays()))->delete();
        $cutoff = Miss::query()->orderByDesc('last_seen_at')->orderByDesc('id')->offset($this->settings->maxMisses())->first(['id', 'last_seen_at']);

        if ($cutoff === null) {
            return $pruned;
        }

        return $pruned + Miss::query()
            ->where(fn ($older) => $older->where('last_seen_at', '<', $cutoff->last_seen_at)
                ->orWhere(fn ($same) => $same->where('last_seen_at', $cutoff->last_seen_at)->where('id', '<=', $cutoff->id)))
            ->delete();
    }

    /* @return int the rows it counted, none for an address not logged yet */
    private function countAgain(string $hash, array $seen): int
    {
        return Miss::query()->where('address_hash', $hash)->update(['hits' => DB::raw('hits + 1'), ...$seen]);
    }
}
