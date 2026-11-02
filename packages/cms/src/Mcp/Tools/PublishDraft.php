<?php

namespace Mainstay\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Mainstay\Mcp\Tool;

class PublishDraft extends Tool
{
    protected string $description = 'Puts a draft live, so visitors see it, and hands back the entry or global as it is now.';

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()->description('The draft\'s id.')->required()];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->answer(fn () => $this->operations->publish((int) $request->get('id')));
    }
}
