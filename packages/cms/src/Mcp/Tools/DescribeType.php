<?php

namespace Mainstay\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Mainstay\Mcp\Tool;

#[IsReadOnly]
class DescribeType extends Tool
{
    protected string $description = 'One content type, with `input`: the JSON Schema of the fields a write takes.';

    public function schema(JsonSchema $schema): array
    {
        return ['type' => $this->type($schema)];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->answer(fn () => $this->operations->describe((string) $request->get('type')));
    }
}
