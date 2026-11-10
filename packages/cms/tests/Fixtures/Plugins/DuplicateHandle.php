<?php

namespace Mainstay\Tests\Fixtures\Plugins;

use Mainstay\Admin\Widget;

/* A widget claiming the handle of another. */
class DuplicateHandle extends Widget
{
    public static function handle(): string
    {
        return 'dashed_metrics';
    }

    public function title(): string
    {
        return 'Duplicate';
    }

    public function view(): string
    {
        return 'dashed::widgets.note';
    }
}
