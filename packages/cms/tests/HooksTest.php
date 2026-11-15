<?php

namespace Mainstay\Tests;

use Mainstay\Hook;
use Mainstay\Hooks;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class HooksTest extends TestCase
{
    #[Test]
    public function actions_run_by_priority_then_in_the_order_they_were_added(): void
    {
        $hooks = new Hooks;
        $ran = [];

        $hooks->addAction('now', function () use (&$ran) {
            $ran[] = 'late';
        }, 20);
        $hooks->addAction('now', function () use (&$ran) {
            $ran[] = 'first of ten';
        });
        $hooks->add(Hook::action('now', function () use (&$ran) {
            $ran[] = 'second of ten';
        }));
        $hooks->addAction('now', function () use (&$ran) {
            $ran[] = 'early';
        }, 1);

        $hooks->doAction('now');

        $this->assertSame(['early', 'first of ten', 'second of ten', 'late'], $ran);
    }

    #[Test]
    public function a_filter_hands_its_value_down_the_chain_with_the_arguments(): void
    {
        $hooks = new Hooks;
        $hooks->addFilter('title', fn (string $title, string $suffix) => "{$title}{$suffix}", 20);
        $hooks->addFilter('title', fn (string $title) => strtoupper($title), 10);

        $this->assertSame('ADA!', $hooks->applyFilters('title', 'ada', '!'));
    }

    #[Test]
    public function a_name_nobody_hooked_hands_the_value_back(): void
    {
        $this->assertSame(['x'], (new Hooks)->applyFilters('nothing', ['x']));
    }
}
