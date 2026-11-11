<?php

namespace Mainstay\Chat;

use Exception;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Mainstay\Ai\Connectors;

/*
 | Whether the provider chosen for text has what it needs to answer. Not
 | Connectors::all(), which is for whoever manages connectors: the chat's
 | people need not.
 */
class TextConnector
{
    /* Drivers that run without a key. */
    private const KEYLESS = ['ollama'];

    public function ready(): bool
    {
        $name = app(Connectors::class)->defaults()['text']['provider'];

        if ($name === null) {
            return false;
        }

        try {
            $provider = app(AiManager::class)->instance($name);
        } catch (Exception) {
            return false;
        }

        return $provider instanceof TextProvider
            && (filled($provider->providerCredentials()['key'] ?? null) || in_array(config("ai.providers.{$name}.driver"), self::KEYLESS, true));
    }
}
