<?php

namespace Mainstay\Seo\Tests\Fixtures;

use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Mainstay\Content\Entry;
use Mainstay\Fields\Text;
use Mainstay\Site\Route;

/* Routed, and read by whoever is signed in and by no visitor. */
#[Route('/members/{slug}')]
#[UsePolicy(MembersPolicy::class)]
class Members extends Entry
{
    #[Text(required: true, localized: true)]
    public string $slug;
}
