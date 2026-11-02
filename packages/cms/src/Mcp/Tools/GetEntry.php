<?php

namespace Mainstay\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Mainstay\Content\Entry;
use Mainstay\Mcp\Tool;

#[IsReadOnly]
class GetEntry extends Tool
{
    protected string $description = 'One published entry by its id.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $this->type($schema, Entry::class),
            'id' => $schema->integer()->required(),
            'locale' => $this->locale($schema),
            'depth' => $this->depth($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->answer(fn () => $this->operations->get((string) $request->get('type'), (int) $request->get('id'), $request->get('locale'), $request->get('depth')));
    }
}
