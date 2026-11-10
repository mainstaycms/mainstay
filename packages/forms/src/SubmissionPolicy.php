<?php

namespace Mainstay\Forms;

use Illuminate\Auth\Access\Response;
use Mainstay\Auth\User;
use Mainstay\Content\Entry;
use Mainstay\Policies\EntryPolicy;

/*
 | What an entry lets be done, except writing it: a submission is read and
 | deleted by whoever holds the capabilities, and made only by code that says
 | it writes on its own authority, `overrideAccess: true`, which never
 | reaches a policy.
 */
class SubmissionPolicy extends EntryPolicy
{
    private const READ_ONLY = 'A submission is what a visitor sent: it is read and deleted, never written.';

    public function create(?User $user, string $type): Response
    {
        return Response::deny(self::READ_ONLY);
    }

    public function update(?User $user, Entry $entry): Response
    {
        return Response::deny(self::READ_ONLY);
    }

    public function publish(?User $user, Entry|string $entry): Response
    {
        return Response::deny(self::READ_ONLY);
    }

    public function assign(?User $user, Entry $entry): Response
    {
        return Response::deny(self::READ_ONLY);
    }
}
