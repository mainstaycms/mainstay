<?php

namespace Mainstay\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Mainstay\Content\Entry;
use Mainstay\Mcp\Tool;

class CreateEntry extends Tool
{
    protected string $description = 'Makes an entry and puts it live at once. To leave it for a person to publish, use save-draft.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $this->type($schema, Entry::class),
            'data' => $this->data($schema),
            'locale' => $this->locale($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->answer(fn () => $this->operations->create((string) $request->get('type'), (array) $request->get('data', []), $request->get('locale')));
    }
}
