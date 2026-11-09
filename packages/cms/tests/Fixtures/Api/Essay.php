<?php

namespace Mainstay\Tests\Fixtures\Api;

use Carbon\CarbonImmutable;
use Mainstay\Content\Document;
use Mainstay\Content\Entry;
use Mainstay\Content\Media;
use Mainstay\Content\PublicRead;
use Mainstay\Fields\Blocks;
use Mainstay\Fields\Date;
use Mainstay\Fields\Image;
use Mainstay\Fields\Internal;
use Mainstay\Fields\Relation;
use Mainstay\Fields\RichText;
use Mainstay\Fields\Terms;
use Mainstay\Fields\Text;
use Mainstay\Site\Route;
use Mainstay\Tests\Fixtures\Linked\Blocks\Shelf;
use Mainstay\Tests\Fixtures\Linked\Genre;
use Mainstay\Tests\Fixtures\Linked\Person;

/* What the API is checked against: anyone may read it, and it points both
   at a type anyone may read and at ones only a token may -- in a field, in
   a list of several, as terms and two blocks down in its content -- beside
   a moment, an image, a document and a note only its editors see. */
#[PublicRead]
#[Route('/essays/{slug}')]
#[Blocks([Shelf::class])]
class Essay extends Entry
{
    #[Text(required: true, localized: true)]
    public string $title;

    #[Text(required: true, localized: true)]
    public string $slug;

    #[Date(time: true)]
    public ?CarbonImmutable $publishedAt;

    #[Image(sizes: ['card' => [32, 32]])]
    public ?Media $cover;

    #[Relation(to: Essayist::class)]
    public ?Essayist $author;

    #[Relation(to: [Essayist::class, Person::class])]
    public array $picks;

    #[Terms(of: Genre::class)]
    public array $genres;

    #[RichText]
    public ?Document $body;

    #[Internal]
    #[Text]
    public ?string $note;
}
