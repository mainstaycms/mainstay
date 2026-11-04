<?php

namespace Mainstay\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Mainstay\Mcp\Tool;

#[IsDestructive]
class DiscardDraft extends Tool
{
    protected string $description = 'Deletes a draft, and the changes in it with it. What is live stays.';

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()->description('The draft\'s id.')->required()];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->answer(fn () => $this->operations->discard((int) $request->get('id')));
    }
}
