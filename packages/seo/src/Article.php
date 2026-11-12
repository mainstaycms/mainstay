<?php

namespace Mainstay\Seo;

use Attribute;

/*
 | Marks an entry type as one whose pages are articles for structured data,
 | and names the properties that say when and by whom:
 |
 |     #[Article(published: 'publishedAt', author: 'author')]
 |
 | `published` is a date property. `author` is a text property, or one that
 | holds an entry or an object with a title or a name. A type without the
 | attribute gets no Article markup; its last change is the entry's own
 | updatedAt.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Article
{
    public function __construct(public readonly string $published, public readonly ?string $author = null) {}
}
