<?php

namespace Mainstay\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/* An entry out of the trash and live again: heard after the commit, as
   Saved is. */
final class Restored implements ShouldDispatchAfterCommit
{
    /** @param  class-string  $type */
    public function __construct(public readonly string $type, public readonly int $id) {}
}
