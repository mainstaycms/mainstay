<?php

namespace Mainstay\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Mainstay\Mcp\Tool;

#[IsReadOnly]
class GetDraft extends Tool
{
    protected string $description = 'A draft, by its own id, or the one of an entry -- or of a global, naming no entry -- with the fields it changes.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('The draft\'s own id; leave out to read an entry\'s by type and entry.'),
            'type' => $schema->string()->description('The type, by its handle, when reading an entry\'s or a global\'s draft.'),
            'entry' => $schema->integer()->description('The entry whose draft to read; left out for a global.'),
            'locale' => $this->locale($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->answer(fn () => $request->get('id') !== null
            ? $this->operations->draft((int) $request->get('id'), $request->get('locale'))
            : $this->operations->draftOf((string) $request->get('type'), $request->get('entry'), $request->get('locale')));
    }
}
