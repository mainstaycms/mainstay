<?php

namespace Mainstay\Content;

use Attribute;

/*
 | A type anyone may read over the API with no token: its published entries,
 | or its global, and no internal field of it. Every other type answers only
 | a token. The site's own pages serve every type whatever this says -- the
 | public sees those as HTML anyway -- so this decides the API alone.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class PublicRead {}
