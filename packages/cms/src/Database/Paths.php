<?php

namespace Mainstay\Database;

use Closure;
use Illuminate\Validation\ValidationException;
use Mainstay\Content\Entry;

/*
 | What the store asks of whatever gives entries public addresses, which core
 | has none of: it asks, and a plugin answers. Core binds NoPaths, and a
 | plugin binds its own over it when it boots.
 |
 | Every write method runs inside the store's transaction, after the store
 | has asked Gate; a method that changes what is live is called inside the
 | store's filing, so the revision is the store's. `$nested` is true where a
 | plain read would answer from a caller's older snapshot, and what a write
 | is decided on is then read with a lock.
 */
interface Paths
{
    /* Whether the type has an address to give its entries. */
    public function routed(string $type): bool;

    /**
     | The rules and messages a save of the type adds to its fields' own, for
     | the fields an address is built from.
     |
     | @return array{0: array<string, list<mixed>>, 1: array<string, string>}
     */
    public function rules(string $type): array;

    /* The field the end of an entry's address is typed in, for a form to
       offer as a slug. */
    public function slugField(string $type): ?string;

    /*
     | Brings an entry's addresses in line with what was just written, or
     | refuses the write with a ValidationException naming the field to blame.
     | `$reads` is whether the caller may read the type: the refusal spells
     | the address out only to one who may.
     */
    public function settle(string $type, int $id, int $site, bool $created, bool $reads, bool $nested): void;

    /*
     | Moves an entry's fields to free addresses where its own are taken, as
     | a restore from the trash must. It changes what is live, so the store
     | calls it inside its filing.
     */
    public function resolveClashes(string $type, int $id, int $site, bool $nested): void;

    /* An entry's addresses, gone: it is in the trash, or deleted for good. */
    public function forget(string $type, int $id): void;

    /**
     * @return array<string, string> an entry's addresses by locale
     */
    public function held(string $type, int $id): array;

    /**
     * @param  list<int>  $ids
     * @return array<int, string> the address each of the entries has in a locale, by id, for those that have one
     */
    public function uris(string $type, array $ids, string $locale): array;

    /**
     * @return array{0: string, 1: int}|null the type's handle and the id of the entry an address leads to
     */
    public function lookup(string $uri, string $locale, int $site): ?array;

    /* Where the entry is linked to, or null for one with no address or not read with it. */
    public function url(Entry $entry): ?string;

    /**
     * @return array{0: class-string<Entry>, 1: int}|null the entry served at `/`
     */
    public function frontPage(int $site): ?array;

    /**
     * @throws ValidationException for an entry that must not leave the site
     */
    public function guardTrashing(string $type, int $id, int $site): void;

    /**
     | Makes `$new` the entry served at `/`, or none, and gives `$old` the
     | address its fields build again. `$filed` runs a change to an entry
     | under the store's filing: it is handed the type, the id and the work.
     |
     | @param  array{0: class-string<Entry>, 1: int}|null  $new
     | @param  array{0: class-string<Entry>, 1: int}|null  $old
     | @param  Closure(class-string<Entry>, int, Closure): mixed  $filed
     */
    public function setFrontPage(int $site, ?array $new, ?array $old, bool $nested, Closure $filed): void;
}
