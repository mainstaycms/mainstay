<?php

namespace Mainstay\Redirects;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Mainstay\Redirects\Console\PruneMisses;

/* Laravel lets only a provider add commands and schedule them; this one is the plugin's, registered from its boot(). */
class RedirectsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PruneMisses::class]);
        }

        $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $schedule->command(PruneMisses::class)->daily());
    }
}
