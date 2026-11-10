<?php

namespace Mainstay\Tests\Fixtures\Slid;

use Mainstay\Fields\Blocks;
use Mainstay\Site\Route;
use Mainstay\Tests\Fixtures\Article as BaseArticle;
use Mainstay\Tests\Fixtures\Blocks\Slide;

/* The fixture Article with slides in its content, so with a page. */
#[Route('/articles/{title}')]
#[Blocks([Slide::class])]
class Article extends BaseArticle {}
