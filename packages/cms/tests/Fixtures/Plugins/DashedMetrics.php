<?php

namespace Mainstay\Tests\Fixtures\Plugins;

use Mainstay\Admin\Widget;
use Mainstay\Auth\User;

/* A widget only whoever holds use_dashed may have, drawn with data. */
class DashedMetrics extends Widget
{
    public static function handle(): string
    {
        return 'dashed_metrics';
    }

    public function title(): string
    {
        return 'Dashed metrics';
    }

    public function view(): string
    {
        return 'dashed::widgets.metrics';
    }

    public function data(User $user): array
    {
        return ['greeting' => "Hello {$user->name}"];
    }

    public function visibleTo(User $user): bool
    {
        return $user->holds('use_dashed');
    }
}
