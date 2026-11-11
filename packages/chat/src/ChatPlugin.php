<?php

namespace Mainstay\Chat;

use Illuminate\Contracts\Foundation\Application;
use Laravel\Ai\AiServiceProvider;
use LogicException;
use Mainstay\Ai\AiConnectors;
use Mainstay\Content\Icon;
use Mainstay\Mcp\McpPlugin;
use Mainstay\Plugin;
use ReflectionClass;

/*
 | A chat in the admin where a language model edits content with the MCP
 | server's tools, as the person signed in and no one else: whatever the
 | person may not do, the model is refused. Reads and drafts run freely; what
 | goes live or deletes waits in the chat for an Approve.
 |
 | It runs on the AI connectors, for the provider and its key, and on the MCP
 | plugin, for the tools. Mainstay checks no plugin's dependencies, so boot()
 | does.
 */
#[Icon(file: __DIR__.'/../resources/icons/chat.svg')]
class ChatPlugin extends Plugin
{
    public const REQUIRES = [AiConnectors::class, McpPlugin::class];

    public static function handle(): string
    {
        return 'chat';
    }

    public static function label(): string
    {
        return 'Chat';
    }

    public function views(): ?string
    {
        return __DIR__.'/../resources/views';
    }

    public function routes(): ?string
    {
        return __DIR__.'/../routes/admin.php';
    }

    public function assets(): array
    {
        return ['chat.css', 'chat.js'];
    }

    /* The conversations are laravel/ai's own tables, whose migration the
       package ships but only publishes: run from where it is, so a host does
       not publish a second copy of it. */
    public function migrations(): ?string
    {
        return dirname((new ReflectionClass(AiServiceProvider::class))->getFileName(), 2).'/database/migrations';
    }

    public function tables(): array
    {
        return [config('ai.conversations.tables.conversations', 'agent_conversations'), config('ai.conversations.tables.messages', 'agent_conversation_messages')];
    }

    public function boot(Application $app): void
    {
        foreach (self::REQUIRES as $required) {
            if (! in_array($required, config('mainstay.plugins', []), true)) {
                throw new LogicException(self::class." needs the plugin {$required} in config('mainstay.plugins'): it brings the AI provider or the tools the chat runs on.");
            }
        }

        $app->register(ChatServiceProvider::class);
    }
}
