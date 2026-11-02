<?php

namespace Mainstay\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/*
 | An entry or a global written live: a create, an update, a global's save.
 | A draft is not live, so saving one is not this; publishing it is
 | Published.
 |
 | Each of Mainstay's events is heard once the write has committed, and not
 | at all when it rolls back, so a listener never acts on content that is not
 | there. They carry the type and id alone, since one write can change every
 | locale's page -- a shared field does -- and a listener reads what it needs.
 */
final class Saved implements ShouldDispatchAfterCommit
{
    /** @param  class-string  $type */
    public function __construct(public readonly string $type, public readonly int $id) {}
}
