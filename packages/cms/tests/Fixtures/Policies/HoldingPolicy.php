<?php

namespace Mainstay\Tests\Fixtures\Policies;

/* A host's own policy that asks holds(), as the README says one must for a
   token's capabilities to bind it: internal fields for whoever may edit
   essays. */
class HoldingPolicy
{
    public function viewAny(?object $user): bool
    {
        return true;
    }

    public function viewInternal(?object $user): bool
    {
        return (bool) $user?->holds('edit_essays');
    }
}
