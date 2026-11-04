<?php

namespace Mainstay\Auth;

use Illuminate\Database\RecordNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mainstay\Mainstay;

/*
 | The tokens an account hands the programs that work for it over the API:
 | `Mainstay::tokens()->issue($user, 'CI build', ['publish_posts'])`. Each
 | holds capabilities of its own and never one its account cannot hand out,
 | as a role never holds one its maker lacks; at use it holds no more than
 | the account does then either. An account makes and revokes only its own,
 | unless `overrideAccess` says the caller acts on its own authority.
 */
final class Tokens
{
    /* What every secret starts with, so a person or a secret scanner knows
       one when they see it. */
    public const PREFIX = 'mst_';

    public function __construct(private Mainstay $mainstay) {}

    /**
     * The token, and its secret: shown this once, since only its sha256 is
     * kept. `*` is every capability its account holds, so it is all it holds.
     *
     * @param  list<string>  $capabilities
     * @return array{0: Token, 1: string}
     */
    public function issue(int $user, string $name, array $capabilities, bool $overrideAccess = false): array
    {
        $owner = $this->owner($user, $overrideAccess);
        Validator::make(['name' => $name, 'capabilities' => $capabilities], [
            'name' => ['required', 'string', 'max:255'],
            'capabilities' => ['array', 'list'],
            'capabilities.*' => ['string'],
        ])->validate();
        $capabilities = array_values(array_unique($capabilities));

        if (($unknown = array_diff($capabilities, ['*'], $this->mainstay->capabilities())) !== []) {
            throw ValidationException::withMessages(['capabilities' => 'No registered type or plugin derives '.reset($unknown).'.']);
        }

        if (($lacking = Capabilities::uncovered($owner, $capabilities)) !== null) {
            throw ValidationException::withMessages(['capabilities' => $lacking === '*'
                ? 'Only an account holding everything can make a token that does.'
                : "This hands out {$lacking}, which {$owner->name} does not hold."]);
        }

        $secret = self::PREFIX.Str::random(48);
        $token = Token::query()->create([
            'user_id' => $owner->id,
            'name' => trim($name),
            'token' => self::hash($secret),
            'capabilities' => in_array('*', $capabilities, true) ? ['*'] : $capabilities,
        ]);

        return [$token, $secret];
    }

    /* Another account's token is not there, to the account asking, as
       another's settings are not. */
    public function revoke(int $id, bool $overrideAccess = false): void
    {
        $token = Token::query()->whereKey($id)->when(! $overrideAccess, fn ($query) => $query->where('user_id', Gate::user()?->getKey()))->first();

        ($token ?? throw new RecordNotFoundException("Token {$id} is not there to revoke."))->delete();
    }

    /** @return Collection<int, Token> an account's tokens, the newest first: none of another's */
    public function of(int $user, bool $overrideAccess = false): Collection
    {
        if (! $overrideAccess && $user !== Gate::user()?->getKey()) {
            return new Collection;
        }

        return Token::query()->where('user_id', $user)->orderByDesc('id')->get();
    }

    /* The token a secret is, read with its account, or null for one that is
       not any. */
    public function find(string $secret): ?Token
    {
        return str_starts_with($secret, self::PREFIX)
            ? Token::query()->with('user.role')->where('token', self::hash($secret))->first()
            : null;
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /* The account a token is made for: the signed-in one's own, unless the
       caller acts on its own authority. Read afresh, so what it holds is
       what it holds now. */
    private function owner(int $user, bool $overrideAccess): User
    {
        if (! $overrideAccess && $user !== Gate::user()?->getKey()) {
            throw new RecordNotFoundException("Account {$user} is not yours to make a token for.");
        }

        return User::query()->with('role')->find($user) ?? throw new RecordNotFoundException("Account {$user} is not there.");
    }
}
