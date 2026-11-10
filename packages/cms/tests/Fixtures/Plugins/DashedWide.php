<?php

namespace Mainstay\Tests\Fixtures\Plugins;

use Mainstay\Admin\Widget;

/* A widget that takes the whole row. */
class DashedWide extends Widget
{
    public static function handle(): string
    {
        return 'dashed_wide';
    }

    public function title(): string
    {
        return 'Dashed wide';
    }

    public function view(): string
    {
        return 'dashed::widgets.wide';
    }

    public function columns(): int
    {
        return 2;
    }
}
