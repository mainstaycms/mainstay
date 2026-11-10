<?php

namespace Mainstay\Parts;

use Mainstay\Admin\Widget;
use Mainstay\Auth\Gate;
use Mainstay\Auth\User;
use Mainstay\Mainstay;
use Mainstay\Navigation;

/*
 | Each part, whether it is published, changed since, only a draft or still
 | empty, linking to where it is edited. For whoever may edit and read a part:
 | each shows only if that user may.
 */
class PartsWidget extends Widget
{
    public function __construct(private Mainstay $mainstay) {}

    public static function handle(): string
    {
        return 'parts';
    }

    public function title(): string
    {
        return 'Template parts';
    }

    public function view(): string
    {
        return 'parts::widgets.parts';
    }

    /** @return array{parts: list<array{label: string, status: string, href: string}>} */
    public function data(User $user): array
    {
        return ['parts' => array_map(fn (string $part) => [
            'label' => Navigation::label($part),
            'status' => $this->status($part),
            'href' => route('mainstay.entries', $part::handle(), absolute: false),
        ], $this->editable())];
    }

    public function visibleTo(User $user): bool
    {
        return $this->editable() !== [];
    }

    private function status(string $part): string
    {
        $live = $this->mainstay->global($part, depth: 0) !== null;
        $draft = $this->mainstay->drafts()->of($part, depth: 0) !== null;

        return match (true) {
            $live && $draft => 'Changed',
            $live => 'Published',
            $draft => 'Draft',
            default => 'Empty',
        };
    }

    /** @return list<class-string<Part>> the parts this user may edit and read */
    private function editable(): array
    {
        return array_values(array_filter(
            $this->mainstay->registered(),
            fn (string $type) => is_subclass_of($type, Part::class)
                && Navigation::writes($type)
                && Gate::about($type)->allows('viewAny', [$type, $type]),
        ));
    }
}
