<?php

namespace Mainstay\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/* A draft put live, with the id of the entry or global it is, not the
   draft's: heard after the commit, as Saved is. */
final class Published implements ShouldDispatchAfterCommit
{
    /** @param  class-string  $type */
    public function __construct(public readonly string $type, public readonly int $id) {}
}
