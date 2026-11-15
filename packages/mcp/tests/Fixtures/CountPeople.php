<?php

namespace Mainstay\Mcp\Tests\Fixtures;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Mainstay\Facades\Mainstay;
use Mainstay\Mcp\Tool;
use Mainstay\Tests\Fixtures\Linked\Person;

/* A plugin's tool, which reads through the store as the caller. */
class CountPeople extends Tool
{
    protected string $description = 'How many people the caller may read.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->answer(fn () => ['people' => Mainstay::find(Person::class)->count()]);
    }
}
