<?php

namespace Mainstay\Chat;

use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Mainstay\Mcp\Server;
use Mainstay\Mcp\Tool as McpTool;
use Mainstay\Mcp\Tools\DiscardDraft;
use Mainstay\Mcp\Tools\SaveDraft;

/*
 | The MCP server's tools, for the chat, and the developer's own from
 | `config('mainstay.chat.tools')`. What a person may ask a model to do
 | without being asked first: read, and write a draft, which nobody sees until
 | it is published, or throw one away. Every other tool -- the live writes, and
 | whatever the MCP plugin adds later -- waits for an Approve. So does a
 | developer's tool, unless it is Approvable and says otherwise.
 */
class ChatTools
{
    /** @var list<class-string<McpTool>> writes that stay in drafts */
    public const FREE_WRITES = [SaveDraft::class, DiscardDraft::class];

    /** @return list<Tool> */
    public static function all(): array
    {
        return [...self::mcp(), ...self::developers()];
    }

    /** @return list<McpToolAdapter> */
    private static function mcp(): array
    {
        return array_map(function (string $class) {
            $tool = app($class);
            $adapter = new McpToolAdapter($tool);

            return self::isFree($tool) ? $adapter : $adapter->requireApproval('This goes live or deletes: '.$tool->description());
        }, Server::TOOLS);
    }

    /** @return list<Tool> */
    private static function developers(): array
    {
        return array_map(function (string $class): Tool {
            $tool = app($class);

            return $tool instanceof Approvable && $tool instanceof Tool ? $tool : new GatedTool($tool);
        }, ChatConfig::tools());
    }

    private static function isFree(McpTool $tool): bool
    {
        return ($tool->annotations()['readOnlyHint'] ?? false) === true || in_array($tool::class, self::FREE_WRITES, true);
    }
}
