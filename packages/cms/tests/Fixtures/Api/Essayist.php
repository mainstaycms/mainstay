<?php

namespace Mainstay\Tests\Fixtures\Api;

use Mainstay\Content\Entry;
use Mainstay\Content\PublicRead;
use Mainstay\Content\Route;
use Mainstay\Fields\Relation;
use Mainstay\Fields\Text;

/* Who writes an essay, pointing back at what they wrote: a relation a
   deeper read follows round again. */
#[PublicRead]
#[Route('/essayists/{slug}')]
class Essayist extends Entry
{
    #[Text(required: true, localized: true)]
    public string $title;

    #[Text(required: true, localized: true)]
    public string $slug;

    #[Relation(to: Essay::class)]
    public array $essays;
}
