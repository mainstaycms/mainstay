<?php

namespace Mainstay\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Mainstay\Api\Operations;
use Mainstay\Content\Entry;
use Mainstay\Mcp\Tool;

#[IsReadOnly]
class FindEntries extends Tool
{
    protected string $description = 'A page of a type\'s published entries, filtered and sorted.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $this->type($schema, Entry::class),
            'where' => $schema->object()->description('Conditions by field: {"status": "live"}, {"readingMinutes": {">": 3}}, {"id": {"in": [1, 2]}}. Operators: = != < <= > >= in not_in.'),
            'sort' => $schema->array()->items($schema->string())->description('Fields to sort by, in order; a leading - sorts descending: ["-publishedAt", "title"].'),
            'page' => $schema->integer()->description('Which page, from 1.'),
            'perPage' => $schema->integer()->description('How many entries a page holds: 15 when left out, at most '.Operations::PER_PAGE.'.'),
            'locale' => $this->locale($schema),
            'depth' => $this->depth($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->answer(fn () => $this->operations->find(
            (string) $request->get('type'),
            where: (array) $request->get('where', []),
            sort: (array) $request->get('sort', []),
            page: (int) $request->get('page', 1),
            perPage: (int) $request->get('perPage', 15),
            locale: $request->get('locale'),
            depth: $request->get('depth'),
        ));
    }
}
