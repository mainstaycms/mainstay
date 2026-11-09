<?php

namespace Mainstay\Database;

use Closure;
use Mainstay\Content\Entry;

/*
 | Core on its own: nothing has an address, so nothing is looked up, built or
 | refused, and an entry reads back with no `uri`.
 */
class NoPaths implements Paths
{
    public function routed(string $type): bool
    {
        return false;
    }

    public function rules(string $type): array
    {
        return [[], []];
    }

    public function slugField(string $type): ?string
    {
        return null;
    }

    public function settle(string $type, int $id, int $site, bool $created, bool $reads, bool $nested): void {}

    public function resolveClashes(string $type, int $id, int $site, bool $nested): void {}

    public function forget(string $type, int $id): void {}

    public function held(string $type, int $id): array
    {
        return [];
    }

    public function uris(string $type, array $ids, string $locale): array
    {
        return [];
    }

    public function lookup(string $uri, string $locale, int $site): ?array
    {
        return null;
    }

    public function url(Entry $entry): ?string
    {
        return null;
    }

    public function frontPage(int $site): ?array
    {
        return null;
    }

    public function guardTrashing(string $type, int $id, int $site): void {}

    public function setFrontPage(int $site, ?array $new, ?array $old, bool $nested, Closure $filed): void {}
}
