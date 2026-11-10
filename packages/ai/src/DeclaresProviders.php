<?php

namespace Mainstay\Ai;

/*
 | A plugin that brings AI providers for the Connectors screen, as entries of
 | laravel/ai's `ai.providers` by name, with a `label` to list them by:
 | `['together' => ['label' => 'Together AI', 'driver' => 'openai-compatible',
 | 'url' => 'https://api.together.xyz/v1']]`. A driver laravel/ai lacks is a
 | closure taking the app and the provider's config and returning a laravel/ai
 | Provider. The host's own `ai.providers` entry of the name wins key by key,
 | so its config gives one a key from its env.
 */
interface DeclaresProviders
{
    /** @return array<string, array<string, mixed>> */
    public function providers(): array;
}
