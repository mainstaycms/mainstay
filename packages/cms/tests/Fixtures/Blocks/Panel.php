<?php

namespace Mainstay\Tests\Fixtures\Blocks;

use Mainstay\Content\Block;
use Mainstay\Content\Example;
use Mainstay\Fields\Blocks;

/* A block of no fields holding text and slides inside it, previewed with
   both. */
#[Example(inner: [['h2' => 'Opening hours'], Slide::class, ['p' => 'Closed on Mondays.']])]
#[Blocks([Slide::class], text: true)]
class Panel extends Block {}
