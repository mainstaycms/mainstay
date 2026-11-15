<?php

namespace Mainstay\Tests\Fixtures;

use Mainstay\Content\Entry;
use Mainstay\Content\Media;
use Mainstay\Fields\File;
use Mainstay\Fields\Image;
use Mainstay\Fields\Text;
use Mainstay\Site\Route;

/* A brochure a field takes only as a PDF or a Word file, a sheet in each
   language of any allowed document, and a photo, which takes no document. */
#[Route('/brochured/{slug}')]
class Brochured extends Entry
{
    #[Text(required: true)]
    public string $slug;

    #[File(accept: ['pdf', 'docx'])]
    public ?Media $brochure;

    #[File(localized: true)]
    public ?Media $sheet;

    #[Image(sizes: ['card' => [40]])]
    public ?Media $photo;
}
