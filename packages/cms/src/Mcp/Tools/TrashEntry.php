<?php

namespace Mainstay\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Mainstay\Content\Entry;
use Mainstay\Mcp\Tool;

#[IsDestructive]
class TrashEntry extends Tool
{
    protected string $description = 'Moves an entry to the trash, off the site; the admin restores it from there.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $this->type($schema, Entry::class),
            'id' => $schema->integer()->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->answer(fn () => $this->operations->trash((string) $request->get('type'), (int) $request->get('id')));
    }
}
