<?php

namespace Mainstay\Parts\Tests\Fixtures;

use Mainstay\Fields\Blocks;
use Mainstay\Parts\Part;

/* A part that allows less than the footer does. */
#[Blocks([Legal::class])]
class Menu extends Part {}
