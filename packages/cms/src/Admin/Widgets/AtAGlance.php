<?php

namespace Mainstay\Admin\Widgets;

use Mainstay\Admin\Widget;
use Mainstay\Auth\Gate;
use Mainstay\Auth\User;
use Mainstay\Content\GlobalSet;
use Mainstay\Content\HoldsPage;
use Mainstay\Content\Taxonomy;
use Mainstay\Mainstay;
use Mainstay\Navigation;

/*
 | How much there is, each count linking to the screen that lists it: the live
 | entries of every entry type and the media library, of what this user may
 | read and open. Counted in the default locale, as the lists open.
 */
class AtAGlance extends Widget
{
    public function __construct(private Mainstay $mainstay) {}

    public static function handle(): string
    {
        return 'at_a_glance';
    }

    public function title(): string
    {
        return 'At a glance';
    }

    public function view(): string
    {
        return 'mainstay::widgets.at-a-glance';
    }

    /** @return array{counts: list<array{label: string, count: int, href: string}>} */
    public function data(User $user): array
    {
        $counts = array_map(
            fn (string $type) => [
                'label' => Navigation::label($type),
                'count' => $this->mainstay->paginate($type, perPage: 1, depth: 0)->total(),
                'href' => route('mainstay.entries', $type::handle(), absolute: false),
            ],
            array_values(array_filter($this->mainstay->registered(), $this->listed(...))),
        );

        if (Navigation::library()) {
            $counts[] = ['label' => 'Media', 'count' => $this->mainstay->media()->paginate(perPage: 1)->total(), 'href' => route('mainstay.media', absolute: false)];
        }

        return ['counts' => $counts];
    }

    /* An entry type whose list this user can open, and so read. */
    private function listed(string $type): bool
    {
        return ! is_subclass_of($type, GlobalSet::class)
            && ! is_subclass_of($type, Taxonomy::class)
            && ! is_subclass_of($type, HoldsPage::class)
            && Navigation::writes($type)
            && Gate::about($type)->allows('viewAny', [$type, $type]);
    }
}
