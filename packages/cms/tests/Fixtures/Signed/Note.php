<?php

namespace Mainstay\Tests\Fixtures\Signed;

use Mainstay\Content\Entry;
use Mainstay\Fields\Internal;
use Mainstay\Fields\Text;
use Mainstay\Site\Route;

#[Route('/notes/{slug}')]
class Note extends Entry
{
    #[Text(required: true)]
    public string $title;

    #[Text(required: true)]
    public string $slug;

    #[Internal]
    #[Text]
    public ?string $memo;
}
