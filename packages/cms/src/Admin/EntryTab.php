<?php

namespace Mainstay\Admin;

/*
 | A tab a plugin adds to an entry's form beside Details, which the form draws
 | without knowing what is in it. The panel is a view it fills with `data()`;
 | the toolbar, when it has one, is drawn at the end of the tab bar while the
 | tab is open, and the aside in the publishing rail's place. Both are given
 | the same data. The tab's behaviour is the plugin's script, which finds this
 | markup by its own attributes.
 */
interface EntryTab
{
    /* The id of its panel, and of its button in the tab bar. */
    public function handle(): string;

    public function label(): string;

    /* Whether an entry form of this type has the tab. */
    public function applies(string $type): bool;

    /* The view the panel draws. */
    public function panel(): string;

    /** @return array<string, mixed> what the panel, the toolbar and the aside are drawn with */
    public function data(EntryTabContext $context): array;

    public function toolbar(): ?string;

    public function aside(): ?string;
}
