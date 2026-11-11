<?php

namespace Mainstay\Chat;

use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

/*
 | What the chat tells a person when a run dies mid-stream. Never the
 | exception's own message: a provider's error body can echo the key it
 | refused. The log gets ProviderFailed for the same reason.
 */
class ProviderFailure
{
    public static function describe(Throwable $failure, bool $canConnect): string
    {
        $status = self::statusOf($failure);

        return match (true) {
            in_array($status, [401, 403], true) => "The provider rejected the request ({$status}). ".($canConnect ? 'Check the key on the Connectors screen.' : "Ask someone who manages the site's connectors to check the key."),
            $failure instanceof RateLimitedException, $status === 429 => 'The provider is limiting requests. Try again in a moment.',
            $failure instanceof InsufficientCreditsException, $status === 402 => 'The provider account is out of credit or quota.',
            $failure instanceof ProviderConnectionException => 'Could not reach the provider. Try again in a moment.',
            $failure instanceof ProviderOverloadedException, $status >= 500 => 'The provider is unavailable right now. Try again in a moment.',
            default => 'The chat could not finish its answer. Try again.',
        };
    }

    public static function statusOf(Throwable $failure): ?int
    {
        return $failure instanceof RequestException ? $failure->response->status() : null;
    }
}
