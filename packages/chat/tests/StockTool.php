<?php

namespace Mainstay\Chat\Tests;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/* A developer's tool that says nothing about approval. */
class StockTool implements Tool
{
    public static int $runs = 0;

    public function description(): string
    {
        return 'Counts the stock.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        self::$runs++;

        return 'Forty in stock.';
    }
}
