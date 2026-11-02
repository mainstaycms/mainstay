<?php

namespace Mainstay\Tests\Fixtures\Plugins;

/* What the plugin's listener heard, in order, for a test to read back. */
class Heard
{
    /** @var list<object> */
    public static array $events = [];

    public function handle(object $event): void
    {
        self::$events[] = $event;
    }
}
