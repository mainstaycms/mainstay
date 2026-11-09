<?php

namespace Mainstay\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Mainstay\Mcp\Tool;

class SaveDraft extends Tool
{
    protected string $description = 'Writes changes into a draft, which nobody sees until it is published: an entry\'s, one already started by its id, a global\'s, or with neither a new entry\'s. Only fields that differ from what is live are kept, and a draft left changing nothing is deleted.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $this->type($schema),
            'data' => $this->data($schema),
            'entry' => $schema->integer()->description('The published entry the draft changes.'),
            'draft' => $schema->integer()->description('A draft already started, by its id: a new entry\'s, to go on with it.'),
            'locale' => $this->locale($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->answer(fn () => $this->operations->saveDraft((string) $request->get('type'), (array) $request->get('data', []), $request->get('entry'), $request->get('draft'), $request->get('locale')));
    }
}
