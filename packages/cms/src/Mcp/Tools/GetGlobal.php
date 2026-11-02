<?php

namespace Mainstay\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Mainstay\Content\GlobalSet;
use Mainstay\Mcp\Tool;

#[IsReadOnly]
class GetGlobal extends Tool
{
    protected string $description = 'A global as it is live: the site\'s one of a kind, its settings or menu.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $this->type($schema, GlobalSet::class),
            'locale' => $this->locale($schema),
            'depth' => $this->depth($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->answer(fn () => $this->operations->global((string) $request->get('type'), $request->get('locale'), $request->get('depth')));
    }
}
