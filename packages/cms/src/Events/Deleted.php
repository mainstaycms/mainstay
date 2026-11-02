<?php

namespace Mainstay\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/* An entry moved to the trash, its paths gone with it: heard after the
   commit, as Saved is. */
final class Deleted implements ShouldDispatchAfterCommit
{
    /** @param  class-string  $type */
    public function __construct(public readonly string $type, public readonly int $id) {}
}
