<?php

namespace Mainstay\Chat;

use Mainstay\Mcp\Server;
use Mainstay\Mcp\Tool;
use Mainstay\Mcp\Tools\DiscardDraft;
use Mainstay\Mcp\Tools\SaveDraft;

/*
 | The MCP server's tools, for the chat. What a person may ask a model to do
 | without being asked first: read, and write a draft, which nobody sees until
 | it is published, or throw one away. Every other tool -- the live writes, and
 | whatever the MCP plugin adds later -- waits for an Approve.
 */
class ChatTools
{
    /** @var list<class-string<Tool>> writes that stay in drafts */
    public const FREE_WRITES = [SaveDraft::class, DiscardDraft::class];

    /** @return list<McpToolAdapter> */
    public static function all(): array
    {
        return array_map(function (string $class) {
            $tool = app($class);
            $adapter = new McpToolAdapter($tool);

            return self::isFree($tool) ? $adapter : $adapter->requireApproval('This goes live or deletes: '.$tool->description());
        }, Server::TOOLS);
    }

    private static function isFree(Tool $tool): bool
    {
        return ($tool->annotations()['readOnlyHint'] ?? false) === true || in_array($tool::class, self::FREE_WRITES, true);
    }
}
