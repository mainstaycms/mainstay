<?php

namespace Mainstay\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Mainstay\Mcp\Tool;

#[IsReadOnly]
class GetEntryByPath extends Tool
{
    protected string $description = 'The published entry served at a path of the site, whatever its type: "/blog/hello", or "/" for the front page.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('The path, without the locale\'s base.')->required(),
            'locale' => $this->locale($schema),
            'depth' => $this->depth($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->answer(fn () => $this->operations->path((string) $request->get('path'), $request->get('locale'), $request->get('depth')));
    }
}
