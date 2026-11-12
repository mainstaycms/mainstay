<?php

namespace Mainstay\Chat;

use Illuminate\Contracts\Foundation\Application;
use Laravel\Ai\AiServiceProvider;
use LogicException;
use Mainstay\Ai\AiConnectors;
use Mainstay\Content\Icon;
use Mainstay\Mcp\McpPlugin;
use Mainstay\Plugin;
use Mainstay\Settings\Setting;
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
    /* The owner's guidance on tone and content, as Mainstay::setting() names it. */
    public const OWNER_INSTRUCTIONS = 'chat.instructions';

    /* Characters, which keeps the owner's text a guide and not a second prompt. */
    public const OWNER_INSTRUCTIONS_MAX = 2000;

    public const REQUIRES = [AiConnectors::class, McpPlugin::class];

    public static function handle(): string
    {
        return 'chat';
    }

    public static function label(): string
    {
        return 'Chat';
    }

    public function settings(): array
    {
        return [
            new Setting(
                'instructions',
                'Instructions for the chat',
                'textarea',
                'How the chat should sound and what it should keep to. It shapes the writing only: what it may change, and what waits for your approval, is not up to it.',
                default: '',
                placeholder: "Write in a warm, plain tone, and in Dutch unless I ask for English.\nNever change the pricing page. Keep headlines under 60 characters.",
                rules: ['max:'.self::OWNER_INSTRUCTIONS_MAX],
            ),
        ];
    }

    public function views(): ?string
    {
        return __DIR__.'/../resources/views';
    }

    public function tools(): ?string
    {
        return 'chat::tools';
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

        ChatConfig::instructions();
        ChatConfig::tools();

        $app->register(ChatServiceProvider::class);
    }
}
