<?php

namespace Mainstay\Tests\Fixtures\Blocks;

use Mainstay\Content\Block;
use Mainstay\Content\Example;
use Mainstay\Fields\Blocks;

/* A block that holds its own kind inside it, as deep as it is written, its
   example one of itself. */
#[Example(inner: [Looped::class])]
#[Blocks([Looped::class])]
class Looped extends Block {}
