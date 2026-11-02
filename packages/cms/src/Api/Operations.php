<?php

namespace Mainstay\Api;

use Illuminate\Database\RecordNotFoundException;
use Mainstay\Auth\Gate;
use Mainstay\Content\Entry;
use Mainstay\Content\GlobalSet;
use Mainstay\Content\PublicRead;
use Mainstay\Content\Taxonomy;
use Mainstay\Database\BadQuery;
use Mainstay\Database\ContentStore;
use Mainstay\Mainstay;
use Mainstay\Navigation;
use ReflectionClass;

/*
 | What the API does, once, for REST and MCP alike: a type found by its
 | handle, the call made to the query layer as the caller -- whose Gate
 | answers -- and the answer as Payload hands it out. Each transport turns
 | what is thrown into its own refusal, so the two cannot drift: a type,
 | an entry or a draft that is not there is RecordNotFoundException, a
 | call the layer does not take is BadQuery, and a refusal is Gate's.
 */
final class Operations
{
    /* The most entries a page holds. */
    public const PER_PAGE = 100;

    /* How far a read follows relations, with a token and with none. A
       nested entry is copied wherever it is pointed at, so each level
       multiplies what one request can make the server write out. */
    public const DEPTH = 3;

    public const PUBLIC_DEPTH = 1;

    /* What a caller with no token is told on being refused. */
    public const NOBODY = 'Without a token, only the types marked #[PublicRead] can be read, and nothing written.';

    public function __construct(private Mainstay $mainstay, private Payload $payload, private Schema $schema) {}

    /** @return list<array<string, mixed>> the types the caller may read */
    public function types(): array
    {
        return array_map($this->described(...), $this->schema->readable());
    }

    public function describe(string $handle): array
    {
        $type = $this->type($handle);
        Gate::about($type)->authorize('viewAny', [$type, $type]);

        return $this->described($type);
    }

    public function schema(): array
    {
        return $this->schema->document();
    }

    /** @return array{data: list<array<string, mixed>>, meta: array<string, int>} */
    public function find(string $handle, array $where = [], string|array $sort = [], int $page = 1, int $perPage = 15, ?string $locale = null, ?int $depth = null): array
    {
        if ($perPage > self::PER_PAGE) {
            throw new BadQuery('A page holds at most '.self::PER_PAGE." entries; {$perPage} is more.");
        }

        if ($page < 1) {
            throw new BadQuery("Pages are counted from 1; {$page} is not one.");
        }

        $entries = $this->mainstay->paginate($this->type($handle, Entry::class), where: $where, sort: $this->sort($sort), perPage: $perPage, page: $page, locale: $locale, depth: $this->depth($depth));

        return [
            'data' => array_map($this->payload->of(...), $entries->items()),
            'meta' => ['page' => $entries->currentPage(), 'perPage' => $entries->perPage(), 'total' => $entries->total(), 'lastPage' => $entries->lastPage()],
        ];
    }

    public function get(string $handle, int $id, ?string $locale = null, ?int $depth = null): array
    {
        $entry = $this->mainstay->findById($this->type($handle, Entry::class), $id, locale: $locale, depth: $this->depth($depth));

        return $this->payload->of($entry ?? throw new RecordNotFoundException("There is no {$handle} {$id}."));
    }

    public function path(string $uri, ?string $locale = null, ?int $depth = null): array
    {
        $entry = $this->mainstay->findByUri($uri, locale: $locale, depth: $this->depth($depth));

        return $this->payload->of($entry ?? throw new RecordNotFoundException("Nothing is served at {$uri}."));
    }

    public function create(string $handle, array $data, ?string $locale = null): array
    {
        return $this->payload->of($this->mainstay->create($this->type($handle, Entry::class), $data, locale: $locale));
    }

    public function update(string $handle, int $id, array $data, ?string $locale = null): array
    {
        return $this->payload->of($this->mainstay->update($this->type($handle, Entry::class), $id, $data, locale: $locale));
    }

    /* Into the trash, from which the admin restores it. */
    public function trash(string $handle, int $id): void
    {
        $this->mainstay->delete($this->type($handle, Entry::class), $id);
    }

    public function global(string $handle, ?string $locale = null, ?int $depth = null): array
    {
        $global = $this->mainstay->global($this->type($handle, GlobalSet::class), locale: $locale, depth: $this->depth($depth));

        return $this->payload->of($global ?? throw new RecordNotFoundException("{$handle} has not been written in this locale."));
    }

    public function saveGlobal(string $handle, array $data, ?string $locale = null): array
    {
        return $this->payload->of($this->mainstay->saveGlobal($this->type($handle, GlobalSet::class), $data, locale: $locale));
    }

    /* An entry's draft, or a global's without an entry. */
    public function draftOf(string $handle, ?int $entry = null, ?string $locale = null): array
    {
        $draft = $this->mainstay->drafts()->of($this->type($handle), $entry, $locale);

        return $this->payload->draft($draft ?? throw new RecordNotFoundException("{$handle} has no draft".($entry === null ? '.' : " for {$entry}.")));
    }

    public function draft(int $id, ?string $locale = null): array
    {
        return $this->payload->draft($this->mainstay->drafts()->find($id, $locale) ?? throw new RecordNotFoundException("There is no draft {$id}."));
    }

    /* Null where the draft is left changing nothing, which deletes it. */
    public function saveDraft(string $handle, array $data, ?int $entry = null, ?int $draft = null, ?string $locale = null): ?array
    {
        $saved = $this->mainstay->drafts()->save($this->type($handle), $data, $entry, $draft, $locale);

        return $saved === null ? null : $this->payload->draft($saved);
    }

    public function publish(int $id): array
    {
        return $this->payload->of($this->mainstay->drafts()->publish($id));
    }

    public function discard(int $id): void
    {
        $this->mainstay->drafts()->discard($id);
    }

    /* A registered type by its handle, of the shape the call is for. */
    private function type(string $handle, ?string $shape = null): string
    {
        $type = $this->mainstay->registered()[$handle] ?? null;

        if ($type === null || ($shape !== null && ! is_subclass_of($type, $shape))) {
            throw new RecordNotFoundException(sprintf('There is no %s called %s.', $shape === GlobalSet::class ? 'global' : ($shape === null ? 'type' : 'entry type'), $handle));
        }

        return $type;
    }

    private function described(string $type): array
    {
        return [
            'handle' => $type::handle(),
            'label' => Navigation::label($type, plural: false),
            'shape' => match (true) {
                is_subclass_of($type, GlobalSet::class) => 'global',
                is_subclass_of($type, Taxonomy::class) => 'taxonomy',
                default => 'entry',
            },
            'routed' => $this->mainstay->route($type) !== null,
            'public' => (new ReflectionClass($type))->getAttributes(PublicRead::class) !== [],
            /* What a write takes, which a read hands back with what the
               fields point at loaded: /schema has that. */
            'input' => $this->mainstay->schema($type, internal: Gate::about($type)->allows('viewInternal', [$type, $type])),
        ];
    }

    /* `-publishedAt,title`, as a query string carries it, or a list. */
    private function sort(string|array $sort): array
    {
        return is_array($sort) ? $sort : array_values(array_filter(array_map(trim(...), explode(',', $sort)), fn (string $key) => $key !== ''));
    }

    private function depth(?int $depth): int
    {
        $most = Gate::user() === null ? self::PUBLIC_DEPTH : self::DEPTH;

        if ($depth !== null && $depth > $most) {
            throw new BadQuery("A read over the API follows relations to a depth of at most {$most}".(Gate::user() === null ? ' without a token' : '')."; {$depth} is deeper.");
        }

        return $depth ?? ContentStore::DEPTH;
    }
}
