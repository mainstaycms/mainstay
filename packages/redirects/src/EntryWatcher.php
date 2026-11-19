<?php

namespace Mainstay\Redirects;

/*
 | Mainstay's content hooks, read for what they say about an address. A
 | before hook runs outside the write's transaction and may not write, so it
 | only remembers where the entry was; the after hook runs once the write has
 | committed, sees where it is, and hands the two to AutomaticRedirects. What
 | is kept is one request's, which a worker that outlives it would otherwise
 | carry over: each write takes what it remembered.
 */
class EntryWatcher
{
    /** @var array<string, array<string, string>> */
    private array $before = [];

    public function __construct(private EntryAddresses $addresses, private AutomaticRedirects $automatic) {}

    public function remember(string $type, ?int $id): void
    {
        if ($id !== null) {
            $this->before[$this->key($type, $id)] = $this->addresses->of($type, $id);
        }
    }

    /* A write that may have moved the entry, or made it: nothing remembered
       is a new entry, and its addresses only clear what stood there. */
    public function changed(string $type, int $id): void
    {
        $this->automatic->moved($type, $id, $this->forget($type, $id), $this->addresses->of($type, $id));
    }

    public function trashed(string $type, int $id): void
    {
        $this->automatic->trashed($type, $id, $this->forget($type, $id));
    }

    public function restored(string $type, int $id): void
    {
        $this->automatic->restored($type, $id, $this->addresses->of($type, $id));
    }

    /** @return array<string, string> */
    private function forget(string $type, int $id): array
    {
        $remembered = $this->before[$this->key($type, $id)] ?? [];
        unset($this->before[$this->key($type, $id)]);

        return $remembered;
    }

    private function key(string $type, int $id): string
    {
        return "{$type}:{$id}";
    }
}
