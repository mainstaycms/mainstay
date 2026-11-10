<?php

namespace Mainstay\Mcp;

use Mainstay\Admin\Widget;
use Mainstay\Auth\User;
use ReflectionClass;

/*
 | Where a language model connects, how many tools it is offered, and where
 | to make the token it signs in with. Every account may make tokens, so
 | every account sees it.
 */
class McpWidget extends Widget
{
    public static function handle(): string
    {
        return 'mcp_server';
    }

    public function title(): string
    {
        return 'MCP server';
    }

    public function view(): string
    {
        return 'mcp_server::widgets.server';
    }

    /** @return array{endpoint: string, tools: int, tokens: string} */
    public function data(User $user): array
    {
        return [
            'endpoint' => route('mainstay.api.mcp'),
            'tools' => count((new ReflectionClass(Server::class))->getDefaultProperties()['tools']),
            'tokens' => route('mainstay.tokens', absolute: false),
        ];
    }
}
