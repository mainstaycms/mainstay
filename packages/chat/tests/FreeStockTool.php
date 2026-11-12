<?php

namespace Mainstay\Chat\Tests;

use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Tools\Request;

/* A developer's tool that is Approvable and opts out, as laravel/ai has it do. */
class FreeStockTool extends StockTool implements Approvable
{
    use InteractsWithApprovals;

    protected function needsApproval(Request $request): bool
    {
        return false;
    }
}
