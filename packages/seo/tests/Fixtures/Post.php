<?php

namespace Mainstay\Seo\Tests\Fixtures;

use Carbon\CarbonImmutable;
use Mainstay\Content\Entry;
use Mainstay\Fields\Date;
use Mainstay\Fields\Text;
use Mainstay\Seo\Article;
use Mainstay\Seo\Seo;
use Mainstay\Seo\SeoMeta;
use Mainstay\Site\Route;

/* A post that opts in to Article markup, and in a Dutch path of its own. */
#[Route(['en' => '/blog/{slug}', 'nl' => '/nieuws/{slug}'])]
#[Article(published: 'publishedAt', author: 'author')]
class Post extends Entry
{
    #[Text(required: true, localized: true)]
    public string $slug;

    #[Text]
    public ?string $author;

    #[Date(time: true)]
    public ?CarbonImmutable $publishedAt;

    #[Seo]
    public ?SeoMeta $seo;
}
