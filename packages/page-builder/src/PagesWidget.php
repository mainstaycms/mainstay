<?php

namespace Mainstay\PageBuilder;

use Carbon\CarbonImmutable;
use Mainstay\Admin\Widget;
use Mainstay\Auth\Gate;
use Mainstay\Auth\User;
use Mainstay\Content\Entry;
use Mainstay\Content\Taxonomy;
use Mainstay\Mainstay;
use Mainstay\Navigation;

/*
 | The entries most recently changed, live or as a draft, of the types the
 | Content tab edits, each linking to its form, which opens on Details: the
 | tab is not one an address names. For whoever may edit and read such a type.
 */
class PagesWidget extends Widget
{
    private const LIMIT = 5;

    public function __construct(private Mainstay $mainstay, private ContentTab $tab) {}

    public static function handle(): string
    {
        return 'page_builder';
    }

    public function title(): string
    {
        return 'Pages in the builder';
    }

    public function view(): string
    {
        return 'page_builder::widgets.pages';
    }

    /** @return array{pages: list<array{title: string, status: string, href: string, modified: CarbonImmutable}>} */
    public function data(User $user): array
    {
        $pages = [];

        foreach ($this->types() as $type) {
            array_push($pages, ...$this->changed($type));
        }

        usort($pages, fn (array $first, array $second) => $second['modified'] <=> $first['modified']);

        return ['pages' => array_slice($pages, 0, self::LIMIT)];
    }

    public function visibleTo(User $user): bool
    {
        return $this->types() !== [];
    }

    /** @return list<class-string<Entry>> the types with the tab this user may edit and read */
    private function types(): array
    {
        return array_values(array_filter(
            $this->mainstay->registered(),
            fn (string $type) => is_subclass_of($type, Entry::class)
                && $this->tab->applies($type)
                && Navigation::writes($type)
                && Gate::about($type)->allows('viewAny', [$type, $type]),
        ));
    }

    /** @return list<array{title: string, status: string, href: string, modified: CarbonImmutable}> */
    private function changed(string $type): array
    {
        $handle = $type::handle();
        $rows = [];

        foreach ($this->mainstay->find($type, sort: '-updatedAt', limit: self::LIMIT, depth: 0) as $entry) {
            $rows[$entry->id] = ['title' => $entry->title, 'status' => 'Published', 'href' => route('mainstay.entries.edit', [$handle, $entry->id], absolute: false), 'modified' => $entry->updatedAt];
        }

        /* A term is written live, so it has no drafts to ask for. */
        if (is_subclass_of($type, Taxonomy::class)) {
            return array_values($rows);
        }

        foreach ($this->mainstay->drafts()->all($type, depth: 0) as $draft) {
            /** @var Entry $entry */
            $entry = $draft->entry;
            $rows[$draft->entryId ?? "draft:{$draft->id}"] = [
                'title' => $entry->title,
                'status' => $draft->entryId === null ? 'Draft' : 'Changed',
                'href' => route('mainstay.drafts.edit', [$handle, $draft->id], absolute: false),
                'modified' => $draft->updatedAt,
            ];
        }

        return array_values($rows);
    }
}
