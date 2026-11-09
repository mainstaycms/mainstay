<?php

namespace Mainstay\Tests\Fixtures\Broken;

use Mainstay\Content\Entry;
use Mainstay\Fields\Blocks;
use Mainstay\Site\Route;
use Mainstay\Tests\Fixtures\Blocks\Slide;

/* Text named on a type's blocks, which its content holds already. */
#[Route('/texted/{title}')]
#[Blocks([Slide::class], text: true)]
class Texted extends Entry {}
