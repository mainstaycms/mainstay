<?php

namespace Mainstay\Http;

use Closure;
use Illuminate\Http\Request;
use Mainstay\Auth\Gate;
use Mainstay\Mainstay;
use Symfony\Component\HttpFoundation\Response;

/*
 | Who an API request is: the account behind the bearer token it sends,
 | holding no more than the token does, or nobody, who reads what
 | #[PublicRead] opens. A token that is not one is refused rather than read
 | as nobody, so a revoked one says so instead of quietly reading less.
 */
class AuthenticateToken
{
    /* How stale a token's last use may be before it is written again: a
       build reading a thousand pages writes it once a minute, not a
       thousand times. */
    private const STAMPED = 60;

    public function __construct(private Mainstay $mainstay) {}

    public function handle(Request $request, Closure $next): Response
    {
        $secret = $request->bearerToken();

        if ($secret === null) {
            $request->attributes->set(Gate::PUBLIC, true);

            return $next($request);
        }

        $token = $this->mainstay->tokens()->find($secret);

        if ($token === null) {
            return response()->json(['message' => 'That token is not one this site issued, or it was revoked.'], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        if ($token->last_used_at === null || $token->last_used_at->diffInSeconds(now(), true) > self::STAMPED) {
            $token->forceFill(['last_used_at' => now()])->save();
        }

        $request->attributes->set(Gate::USER, $token->user->limitedTo($token->capabilities));

        return $next($request);
    }
}
