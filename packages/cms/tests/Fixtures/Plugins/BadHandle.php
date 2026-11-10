<?php

namespace Mainstay\Tests\Fixtures\Plugins;

use Mainstay\Admin\Widget;

/* A widget whose handle is not in the format. */
class BadHandle extends Widget
{
    public static function handle(): string
    {
        return 'Bad-Handle';
    }

    public function title(): string
    {
        return 'Bad';
    }

    public function view(): string
    {
        return 'dashed::widgets.note';
    }
}
