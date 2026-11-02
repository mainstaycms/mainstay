<?php

namespace Mainstay\Auth;

use Illuminate\Auth\Access\Gate as AccessGate;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Mainstay\Content\GlobalSet;
use Mainstay\Content\PublicRead;
use Mainstay\Content\Taxonomy;
use Mainstay\Policies\AccountPolicy;
use Mainstay\Policies\EntryPolicy;
use Mainstay\Policies\GlobalPolicy;
use Mainstay\Policies\TermPolicy;
use ReflectionClass;

/*
 | Laravel's Gate, asked about Mainstay's user and never the default guard's:
 | on a host with members of its own, that would be a site visitor answering
 | Mainstay's policies.
 |
 | Built from the host's policies and default denial, and nothing else. Not
 | forUser(), which copies the host's before and after callbacks: Laravel
 | calls those with any user that is not null, and a host's, typed for its
 | own users, would be handed Mainstay's and throw. No guessing a policy by
 | name either: Laravel would match App\Policies\PostPolicy to any class
 | called Post, and a host's Eloquent Post and a content type called Post are
 | different things.
 */
class Gate extends AccessGate
{
    /* Where the admin's middleware puts the signed-in user on the request. */
    public const USER = 'mainstay.user';

    /* The guard nobody is signed in to, which the Content tab draws a page
       with, as a visitor is served it. */
    public const VISITOR = 'mainstay-visitor';

    /* Where the API's middleware marks a request that came with no token,
       whose caller reads only what #[PublicRead] opens. */
    public const PUBLIC = 'mainstay.public';

    /*
     | The policy the host chose for a class -- Gate::policy() on it,
     | #[UsePolicy] on it, Gate::policy() on a class or interface it extends --
     | and `$policy` where it chose none. Built on every call, so a policy the
     | host registers later answers.
     */
    public static function for(string $class, string $policy): self
    {
        /** @var AccessGate $host */
        $host = app(GateContract::class);
        $user = self::user();
        $gate = new self(app(), fn () => $user, policies: $host->policies(), guessPolicyNamesUsingCallback: fn () => []);
        $gate->defaultDenialResponse = $host->defaultDenialResponse;

        if (app('request')->attributes->get(self::PUBLIC) === true) {
            $gate->before(self::publicly(...));
        }

        return $gate->getPolicyFor($class) === null ? $gate->policy($class, $policy) : $gate;
    }

    /*
     | The gate for a content type, with EntryPolicy answering for it unless
     | the host chose another -- GlobalPolicy for a global, TermPolicy for a
     | taxonomy. One policy answers for every type of a shape, so a question
     | about a type rather than an entry hands the type over as well: Gate
     | takes the first of `[$type, $type]` to find the policy and drops it.
     */
    public static function about(string $type): self
    {
        return self::for($type, match (true) {
            is_subclass_of($type, GlobalSet::class) => GlobalPolicy::class,
            is_subclass_of($type, Taxonomy::class) => TermPolicy::class,
            default => EntryPolicy::class,
        });
    }

    /* The gate for accounts and roles, AccountPolicy answering for both
       unless the host chose another. */
    public static function accounts(): self
    {
        $gate = self::for(User::class, AccountPolicy::class);

        return $gate->getPolicyFor(Role::class) === null ? $gate->policy(Role::class, AccountPolicy::class) : $gate;
    }

    /*
     | The user signed in to the admin, or to the API with a token, on a
     | request that came through their middleware, and nobody on any other:
     | a public page, the API without a token and the console read as a
     | visitor whoever is signed in. The guard is not asked, since it would
     | sign the editor in on a public page from the remember cookie, which is
     | sent on every path.
     */
    public static function user(): ?User
    {
        return app('request')->attributes->get(self::USER);
    }

    /*
     | Asked before any policy on the API without a token: no internal field
     | of any type, and no type that is not #[PublicRead] -- whatever the
     | host's policy says, so no policy opens a type to anyone by answering
     | for nobody. Null leaves the rest to the policy. Nullable, or Gate
     | skips it for nobody, the one caller it is for; and an ability may be
     | asked with no arguments, as the query layer's default refusal is.
     */
    private static function publicly(?User $user, string $ability, array $arguments = []): ?bool
    {
        if ($ability === 'viewInternal') {
            return false;
        }

        if ($ability !== 'viewAny' || $arguments === []) {
            return null;
        }

        $class = is_object($arguments[0]) ? $arguments[0]::class : $arguments[0];

        return is_string($class) && class_exists($class) && (new ReflectionClass($class))->getAttributes(PublicRead::class) !== [] ? null : false;
    }
}
