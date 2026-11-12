<?php

namespace Mainstay\Chat;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;
use Stringable;

/*
 | A developer's tool that says nothing about approval, which laravel/ai
 | would run unasked: held for an Approve, as the chat's own live writes are.
 | A tool that is Approvable decides for itself and is never wrapped.
 */
class GatedTool implements Approvable, Tool
{
    use InteractsWithApprovals;

    public function __construct(private Tool $tool) {}

    public function name(): string
    {
        return ToolNameResolver::resolve($this->tool);
    }

    public function description(): Stringable|string
    {
        return $this->tool->description();
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->tool->schema($schema);
    }

    public function handle(Request $request): Stringable|string
    {
        return $this->tool->handle($request);
    }
}
