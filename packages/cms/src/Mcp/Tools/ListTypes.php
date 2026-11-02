<?php

namespace Mainstay\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Mainstay\Mcp\Tool;

#[IsReadOnly]
class ListTypes extends Tool
{
    protected string $description = 'The content types you may read: each one\'s handle, label, shape (entry, taxonomy or global), whether it has pages, whether anyone may read it, and the fields a write takes.';

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->answer(fn () => ['types' => $this->operations->types()]);
    }
}
