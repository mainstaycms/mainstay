<?php

namespace Mainstay\Redirects;

use RuntimeException;

/* A chain of redirects that never ends, which a request is let through
   rather than sent round. */
class RedirectLoop extends RuntimeException
{
    /** @param list<Redirect> $rules */
    public function __construct(public readonly array $rules)
    {
        parent::__construct('These redirects lead round in a circle: '.implode(' → ', array_map(fn (Redirect $rule) => $rule->source, array_slice($rules, 0, 4))).' …');
    }
}
