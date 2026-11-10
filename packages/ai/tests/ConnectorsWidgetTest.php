<?php

namespace Mainstay\Ai\Tests;

use Mainstay\Ai\AiConnectors;
use Mainstay\Ai\Connectors;
use Mainstay\Auth\Role;
use Mainstay\Auth\User;
use Mainstay\Tests\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Test;

/* The AI connectors card on the dashboard: for whoever holds use_ai, counting keys and never showing one. */
class ConnectorsWidgetTest extends DatabaseTestCase
{
    private const KEY = 'sk-from-env';

    private int $made = 0;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mainstay.plugins', [AiConnectors::class]);
    }

    private function signIn(array $capabilities): void
    {
        $n = ++$this->made;
        $role = Role::query()->create(['name' => "role-{$n}", 'capabilities' => $capabilities]);
        $user = User::query()->create(['name' => "Ada {$n}", 'email' => "ada-{$n}@example.com", 'password' => 'correct horse battery', 'role_id' => $role->id]);
        $this->flushSession();
        $this->actingAs($user, 'mainstay');
    }

    #[Test]
    public function it_counts_the_providers_with_a_key_and_links_to_the_connectors_screen(): void
    {
        config(['ai.providers.openai.key' => self::KEY]);
        app(Connectors::class)->save('anthropic', 'sk-saved-secret', overrideAccess: true);
        app(Connectors::class)->choose('text', 'anthropic', overrideAccess: true);

        $this->signIn(['use_ai']);
        $page = $this->get(route('mainstay.admin'))->assertOk()
            ->assertSee('id="widget-ai_connectors"', false)
            ->assertSee('href="'.route('mainstay.plugins.ai.index', absolute: false).'"', false)
            ->assertDontSee(self::KEY)
            ->assertDontSee('sk-saved-secret');

        $this->assertMatchesRegularExpression('#<span class="block text-2xl font-medium">2</span>#', $page->getContent());
        $this->assertMatchesRegularExpression('#Text</span>\s*<span[^>]*>Anthropic</span>#', $page->getContent());
    }

    #[Test]
    public function it_says_none_has_a_key_when_nothing_is_configured(): void
    {
        config(['ai.providers.openai.key' => null]);

        $this->signIn(['use_ai']);
        $this->get(route('mainstay.admin'))->assertOk()
            ->assertSee('<span class="block text-2xl font-medium">0</span>', false);
    }

    #[Test]
    public function it_is_neither_shown_nor_offered_to_someone_without_use_ai(): void
    {
        $this->signIn([]);
        $this->get(route('mainstay.admin'))->assertOk()
            ->assertDontSee('id="widget-ai_connectors"', false)
            ->assertDontSee('AI connectors');

        $this->post(route('mainstay.dashboard.screen'), ['widgets' => ['ai_connectors']])->assertRedirect();
        $this->assertArrayNotHasKey('ai_connectors', auth('mainstay')->user()->fresh()->preference('dashboard.widgets'));
    }
}
