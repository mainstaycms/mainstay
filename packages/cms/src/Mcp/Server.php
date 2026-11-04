<?php

namespace Mainstay\Mcp;

use Laravel\Mcp\Server as McpServer;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Mainstay\Mainstay;

/*
 | The content API for a language model: the same operations as REST, as
 | tools, at /mcp beside it and behind the same token. What a tool may do is
 | what the token's capabilities let it, so a client with a token that only
 | edits drafts can write drafts and never publish them.
 */
#[Name('Mainstay')]
#[Version(Mainstay::VERSION)]
#[Instructions(<<<'TEXT'
    A Mainstay site's content. Its types are classes: list-types names the ones you may read, and describe-type the fields one takes. An entry type has many entries, a global one per site. Reads hand back what is live; a relation or an image is loaded one level deep, or more with depth.

    To change content, write a draft (save-draft) and let a person publish it, unless you were asked to publish: publish-draft and the live writes need capabilities the token may not hold, and a refusal says which. trash-entry moves an entry to the trash, from which the admin restores it.
    TEXT)]
class Server extends McpServer
{
    protected array $tools = [
        Tools\ListTypes::class,
        Tools\DescribeType::class,
        Tools\FindEntries::class,
        Tools\GetEntry::class,
        Tools\GetEntryByPath::class,
        Tools\GetGlobal::class,
        Tools\GetDraft::class,
        Tools\SaveDraft::class,
        Tools\PublishDraft::class,
        Tools\DiscardDraft::class,
        Tools\CreateEntry::class,
        Tools\UpdateEntry::class,
        Tools\TrashEntry::class,
        Tools\SaveGlobal::class,
    ];
}
