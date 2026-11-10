<?php

namespace Mainstay\Admin;

use Mainstay\Auth\User;

/*
 | A card on the admin's dashboard, which the dashboard draws without knowing
 | what is in it. A plugin names its widgets in `Plugin::widgets()` and a host
 | in `Mainstay::widgets()`; each is made by the container, so it may take
 | what it needs in its constructor.
 |
 | Whether the card shows is each user's to choose in Screen options, from the
 | widgets they may see at all: a widget answers that in `visibleTo()`, so
 | someone who cannot use what it shows never sees or lists it.
 */
abstract class Widget
{
    /* Named in what Screen options stores and in the form's checkboxes, so
       it is a plugin's handle's format and no widget shares one. */
    abstract public static function handle(): string;

    /* The card's heading, and its checkbox's label in Screen options. */
    abstract public function title(): string;

    /* The view the card is drawn with, given `data()`. */
    abstract public function view(): string;

    /** @return array<string, mixed> what the view is drawn with */
    public function data(User $user): array
    {
        return [];
    }

    /* Whether this user may have the widget: the capability that makes it
       useful to them, which the widget asks and Screen options does not
       override. */
    public function visibleTo(User $user): bool
    {
        return true;
    }

    /* How many of the dashboard's two columns it spans: 1, or 2 for the full
       row. */
    public function columns(): int
    {
        return 1;
    }

    /* Whether it is on for a user who has not chosen. */
    public function enabledByDefault(): bool
    {
        return true;
    }
}
