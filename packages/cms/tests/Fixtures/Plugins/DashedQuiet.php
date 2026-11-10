<?php

namespace Mainstay\Tests\Fixtures\Plugins;

use Mainstay\Admin\Widget;

/* A widget that is off until a user switches it on. */
class DashedQuiet extends Widget
{
    public static function handle(): string
    {
        return 'dashed_quiet';
    }

    public function title(): string
    {
        return 'Dashed quiet';
    }

    public function view(): string
    {
        return 'dashed::widgets.quiet';
    }

    public function enabledByDefault(): bool
    {
        return false;
    }
}
