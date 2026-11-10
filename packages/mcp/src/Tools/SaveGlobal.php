<?php

namespace Mainstay\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Mainstay\Content\GlobalSet;
use Mainstay\Mcp\Tool;

#[IsIdempotent]
class SaveGlobal extends Tool
{
    protected string $description = 'Changes the fields given of a global, live at once. To leave the change for a person to publish, use save-draft without an entry.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $this->type($schema, GlobalSet::class),
            'data' => $this->data($schema),
            'locale' => $this->locale($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->answer(fn () => $this->operations->saveGlobal((string) $request->get('type'), (array) $request->get('data', []), $request->get('locale')));
    }
}
