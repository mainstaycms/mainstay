<?php

namespace Mainstay\Seo\Tests\Fixtures;

class MembersPolicy
{
    public function viewAny(?object $user): bool
    {
        return $user !== null;
    }

    public function view(?object $user, object $entry): bool
    {
        return $user !== null;
    }
}
