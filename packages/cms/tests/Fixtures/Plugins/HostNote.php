<?php

namespace Mainstay\Tests\Fixtures\Plugins;

use Mainstay\Admin\Widget;

/* A widget of the host, named in its service provider. */
class HostNote extends Widget
{
    public static function handle(): string
    {
        return 'host_note';
    }

    public function title(): string
    {
        return 'Host note';
    }

    public function view(): string
    {
        return 'dashed::widgets.note';
    }
}
