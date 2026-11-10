<?php

namespace Mainstay\Parts\Tests\Fixtures;

use Mainstay\Parts\Part;
use Mainstay\Site\Route;

/* A part with an address, which a part does not have. */
#[Route('/addressed')]
class Addressed extends Part {}
