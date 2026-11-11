<?php

namespace Mainstay\Chat;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Mainstay\Mcp\Tool as McpTool;

/*
 | An MCP server tool as a laravel/ai tool, run in this process: the same
 | class, so the same schema built for the caller, and the same refusals, since
 | the tool asks Gate who is signed in and the admin's middleware has said.
 | Whether it needs the person's approval first is laravel/ai's to enforce,
 | before handle() is ever called.
 */
class McpToolAdapter implements Approvable, Tool
{
    use InteractsWithApprovals;

    public function __construct(private McpTool $tool) {}

    public function name(): string
    {
        return $this->tool->name();
    }

    public function description(): string
    {
        return $this->tool->description();
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->tool->schema($schema);
    }

    public function handle(Request $request): string
    {
        return $this->text($this->tool->handle(new McpRequest($request->all())));
    }

    /* No approval unless asked for: ChatTools asks for every tool that is not
       known to be safe. */
    protected function needsApproval(Request $request): bool
    {
        return false;
    }

    private function text(Response|ResponseFactory $result): string
    {
        if ($result instanceof ResponseFactory) {
            $structured = $result->getStructuredContent();

            return $structured === null
                ? $result->responses()->map(fn (Response $response) => $this->text($response))->implode("\n")
                : json_encode($structured, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        return ($result->isError() ? 'Error: ' : '').$result->content();
    }
}
