<?php

namespace Mainstay\Tests\Fixtures\Plugins;

use Mainstay\Admin\Widget;

/* A widget with a body wider than the others. */
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
}
