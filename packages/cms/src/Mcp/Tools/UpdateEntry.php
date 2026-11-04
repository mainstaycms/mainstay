<?php

namespace Mainstay\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Mainstay\Content\Entry;
use Mainstay\Mcp\Tool;

#[IsIdempotent]
class UpdateEntry extends Tool
{
    protected string $description = 'Changes the fields given of a live entry, live at once. To leave the change for a person to publish, use save-draft.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $this->type($schema, Entry::class),
            'id' => $schema->integer()->required(),
            'data' => $this->data($schema),
            'locale' => $this->locale($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->answer(fn () => $this->operations->update((string) $request->get('type'), (int) $request->get('id'), (array) $request->get('data', []), $request->get('locale')));
    }
}
