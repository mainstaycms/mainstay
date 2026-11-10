<?php

namespace Mainstay\Ai;

use Illuminate\Contracts\Foundation\Application;
use Laravel\Ai\AiManager;
use Laravel\Ai\AiServiceProvider;
use Mainstay\Content\Icon;
use Mainstay\Plugin;

/*
 | The AI providers a site talks to through laravel/ai, kept in the admin: a
 | key per provider and the provider each kind of work goes to. The screens
 | are for whoever holds `use_ai`.
 */
#[Icon(file: __DIR__.'/../resources/icons/connectors.svg')]
class AiConnectors extends Plugin
{
    public static function handle(): string
    {
        return 'ai';
    }

    public function views(): ?string
    {
        return __DIR__.'/../resources/views';
    }

    public function widgets(): array
    {
        return [ConnectorsWidget::class];
    }

    public function routes(): ?string
    {
        return __DIR__.'/../routes/admin.php';
    }

    public function migrations(): ?string
    {
        return __DIR__.'/../database/migrations';
    }

    public function tables(): array
    {
        return ['mainstay_connectors'];
    }

    /*
     | laravel/ai is registered here, explicitly rather than through package
     | discovery, since a host turning discovery off for it would otherwise
     | fail on its facade. What the screen saved is written into its config as
     | it is first made: see Connectors::apply().
     */
    public function boot(Application $app): void
    {
        $app->register(AiServiceProvider::class);
        $app->singleton(Connectors::class);
        $app->afterResolving(AiManager::class, fn (AiManager $ai) => $app->make(Connectors::class)->apply($ai));
        $app->make(Connectors::class)->assertDeclared();
    }
}
