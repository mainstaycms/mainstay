<?php

namespace Mainstay\Parts\Tests\Fixtures;

use Mainstay\Fields\Blocks;
use Mainstay\Parts\Part;

#[Blocks([Brand::class, Legal::class])]
class Footer extends Part {}
