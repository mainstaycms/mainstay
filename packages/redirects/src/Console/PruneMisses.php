<?php

namespace Mainstay\Redirects\Console;

use Illuminate\Console\Command;
use Mainstay\Redirects\MissLog;

/*
 | Drops the 404s the owner's retention settings have no more use for. The
 | plugin schedules it daily; a host without a scheduler runs it from cron.
 */
class PruneMisses extends Command
{
    protected $signature = 'mainstay:redirects:prune';

    protected $description = 'Drop the logged 404s that are past their retention period or over the cap';

    public function handle(MissLog $misses): int
    {
        $this->components->info("Dropped {$misses->prune()} logged 404s.");

        return self::SUCCESS;
    }
}
