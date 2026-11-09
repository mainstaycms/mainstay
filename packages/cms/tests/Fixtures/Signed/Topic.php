<?php

namespace Mainstay\Tests\Fixtures\Signed;

use Mainstay\Content\Taxonomy;
use Mainstay\Fields\Internal;
use Mainstay\Fields\Text;
use Mainstay\Site\Route;

#[Route('/topics/{slug}')]
class Topic extends Taxonomy
{
    #[Text(required: true)]
    public string $title;

    #[Text(required: true)]
    public string $slug;

    #[Internal]
    #[Text]
    public ?string $memo;
}
