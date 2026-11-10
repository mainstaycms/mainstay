<?php

namespace Mainstay\Ai;

use Mainstay\Admin\Widget;
use Mainstay\Auth\User;

/*
 | How many AI providers have a key and the provider each kind of work goes
 | to, linking to the Connectors screen. For whoever holds `use_ai`, as the
 | screen is; a key is never read into it.
 */
class ConnectorsWidget extends Widget
{
    public function __construct(private Connectors $connectors) {}

    public static function handle(): string
    {
        return 'ai_connectors';
    }

    public function title(): string
    {
        return 'AI connectors';
    }

    public function view(): string
    {
        return 'ai::widgets.connectors';
    }

    /** @return array{connected: int, providers: int, defaults: list<array{use: string, provider: string}>, href: string} */
    public function data(User $user): array
    {
        $providers = $this->connectors->all();
        $keyed = array_filter($providers, fn (array $provider) => in_array($provider['key'], ['config', 'saved'], true));
        $defaults = [];

        foreach ($this->connectors->defaults() as $use => $default) {
            if ($default['provider'] !== null) {
                $defaults[] = ['use' => Connectors::USES[$use][2], 'provider' => $this->connectors->label($default['provider'])];
            }
        }

        return [
            'connected' => count($keyed),
            'providers' => count($providers),
            'defaults' => $defaults,
            'href' => route('mainstay.plugins.ai.index', absolute: false),
        ];
    }

    public function visibleTo(User $user): bool
    {
        return $user->holds('use_'.AiConnectors::handle());
    }
}
