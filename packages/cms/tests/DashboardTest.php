<?php

namespace Mainstay\Tests;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Mainstay\Admin\Widgets\AtAGlance;
use Mainstay\Ai\ConnectorsWidget;
use Mainstay\Auth\Role;
use Mainstay\Auth\User;
use Mainstay\Content\Media;
use Mainstay\Facades\Mainstay;
use Mainstay\Forms\SubmissionsWidget;
use Mainstay\Http\Authenticate;
use Mainstay\Mcp\McpWidget;
use Mainstay\PageBuilder\PagesWidget;
use Mainstay\Parts\PartsWidget;
use Mainstay\Site\SiteWidget;
use Mainstay\Tests\Fixtures\Plugins\AbstractWidget;
use Mainstay\Tests\Fixtures\Plugins\BadHandle;
use Mainstay\Tests\Fixtures\Plugins\Dashed;
use Mainstay\Tests\Fixtures\Plugins\DashedMetrics;
use Mainstay\Tests\Fixtures\Plugins\DashedQuiet;
use Mainstay\Tests\Fixtures\Plugins\DashedWide;
use Mainstay\Tests\Fixtures\Plugins\DuplicateHandle;
use Mainstay\Tests\Fixtures\Plugins\HostNote;
use Mainstay\Tests\Fixtures\Policies\ClosedPolicy;
use Mainstay\Tests\Fixtures\Post;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use stdClass;

/*
 | The dashboard's widgets from a plugin's and from the host's own service
 | provider, and each user's Screen options over them: who may have a widget,
 | what is on before anyone has chosen, what a choice keeps and for whom.
 */
class DashboardTest extends DatabaseTestCase
{
    private int $made = 0;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mainstay.plugins', [Dashed::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Mainstay::widgets([HostNote::class]);
        $this->artisan('mainstay:sync')->assertSuccessful();
    }

    private function user(array $capabilities = []): User
    {
        $n = ++$this->made;
        $role = Role::query()->create(['name' => "role-{$n}", 'capabilities' => $capabilities]);

        return User::query()->create(['name' => "Ada {$n}", 'email' => "ada-{$n}@example.com", 'password' => 'correct horse battery', 'role_id' => $role->id]);
    }

    private function signIn(User $user): void
    {
        $this->flushSession();
        $this->actingAs($user, 'mainstay');
    }

    private function refused(callable $load, string $message): void
    {
        $this->assertThrows($load, InvalidArgumentException::class, $message);
    }

    #[Test]
    public function the_widgets_are_the_cores_then_each_plugins_then_the_hosts(): void
    {
        $user = $this->user(['use_dashed']);

        $this->assertSame(
            [AtAGlance::class, DashedMetrics::class, DashedQuiet::class, DashedWide::class, HostNote::class],
            array_map(fn ($widget) => $widget::class, Mainstay::dashboardWidgets($user)),
        );
    }

    #[Test]
    public function a_widget_is_listed_only_to_a_user_it_is_visible_to(): void
    {
        $this->signIn($this->user());
        $page = $this->get(route('mainstay.admin'))->assertOk();
        $page->assertDontSee('Dashed metrics')->assertDontSee('widget-dashed_metrics', false)->assertSee('Host note');

        $this->post(route('mainstay.dashboard.screen'), ['widgets' => ['dashed_metrics', 'host_note']])->assertRedirect(route('mainstay.admin'));
        $this->get(route('mainstay.admin'))->assertDontSee('Dashed metrics')->assertDontSee('Hello Ada');

        $this->signIn($this->user(['use_dashed']));
        $this->get(route('mainstay.admin'))->assertOk()
            ->assertSee('id="widget-dashed_metrics"', false)
            ->assertSee('Hello Ada 2');
    }

    #[Test]
    public function screen_options_are_in_the_top_bar_and_only_on_the_dashboard(): void
    {
        $this->signIn($this->user(['use_dashed']));

        $dashboard = $this->get(route('mainstay.admin'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<header.*data-dialog-open="screen-options".*</header>#s', $dashboard);
        $this->assertStringContainsString('id="screen-options"', $dashboard);
        $this->assertStringContainsString('Counts the dashed things.', $dashboard);
        $this->assertStringNotContainsString('max-w-5xl', $dashboard);
        $this->assertStringContainsString('role="switch"', $dashboard);
        $this->assertStringNotContainsString('border-b border-border px-4 py-3', $dashboard);
        $this->assertStringNotContainsString('rounded-control border border-border bg-surface', $dashboard);
        $this->assertStringContainsString('max-sm:sr-only">Screen options', $dashboard);
        $this->assertStringContainsString('max-sm:sr-only">Visit website', $dashboard);
        $this->assertDoesNotMatchRegularExpression('#<h2 id="widget-[^"]*-title" class="[^"]*border-b#', $dashboard);

        $this->get(route('mainstay.account'))->assertOk()->assertDontSee('Screen options');
    }

    #[Test]
    public function a_widget_is_on_unless_it_is_off_by_default_and_the_user_has_not_chosen(): void
    {
        $this->signIn($this->user(['use_dashed']));

        $page = $this->get(route('mainstay.admin'))->assertOk()
            ->assertSee('Host note body')
            ->assertSee('Wide body')
            ->assertDontSee('Quiet body')
            ->assertSee('id="widget-dashed_quiet"', false);
        $this->assertSame(4, substr_count($page->getContent(), 'aria-labelledby="widget-'));
    }

    #[Test]
    public function screen_options_are_kept_for_the_user_alone(): void
    {
        $first = $this->user(['use_dashed']);
        $second = $this->user(['use_dashed']);

        $this->signIn($first);
        $this->post(route('mainstay.dashboard.screen'), ['widgets' => ['dashed_quiet', 'host_note']])
            ->assertRedirect(route('mainstay.admin'))
            ->assertSessionHas('status', 'Screen options saved.');

        $this->get(route('mainstay.admin'))->assertOk()
            ->assertSee('Screen options saved.')
            ->assertSee('Quiet body')
            ->assertDontSee('Wide body');
        $this->assertSame(
            ['at_a_glance' => false, 'dashed_metrics' => false, 'dashed_quiet' => true, 'dashed_wide' => false, 'host_note' => true],
            $first->fresh()->preference('dashboard.widgets'),
        );

        $this->signIn($second);
        $this->get(route('mainstay.admin'))->assertSee('Wide body')->assertDontSee('Quiet body');
        $this->assertNull($second->fresh()->preference('dashboard.widgets'));
    }

    #[Test]
    public function posting_with_nothing_ticked_turns_every_widget_off_and_says_where_to_turn_them_on(): void
    {
        $this->signIn($this->user(['use_dashed']));
        $this->post(route('mainstay.dashboard.screen'))->assertRedirect(route('mainstay.admin'));

        $page = $this->get(route('mainstay.admin'))->assertOk()
            ->assertSee('No widgets are on. Switch some on in Screen options.')
            ->assertDontSee('Host note body');
        $this->assertSame(0, substr_count($page->getContent(), 'aria-labelledby="widget-'));
    }

    #[Test]
    public function a_handle_the_user_may_not_have_or_that_does_not_exist_is_never_stored(): void
    {
        $user = $this->user();
        $this->signIn($user);
        $this->post(route('mainstay.dashboard.screen'), ['widgets' => ['dashed_metrics', 'nothing_here', 'host_note']])->assertRedirect();

        $this->assertSame(
            ['at_a_glance' => false, 'dashed_quiet' => false, 'dashed_wide' => false, 'host_note' => true],
            $user->fresh()->preference('dashboard.widgets'),
        );
    }

    #[Test]
    public function a_widget_the_user_gained_the_right_to_is_on_or_off_as_its_default_says(): void
    {
        $user = $this->user();
        $this->signIn($user);
        $this->post(route('mainstay.dashboard.screen'), ['widgets' => ['host_note']]);

        $user->role->update(['capabilities' => ['use_dashed']]);
        $this->get(route('mainstay.admin'))->assertSee('Hello Ada 1')->assertDontSee('Quiet body');
    }

    #[Test]
    public function the_cards_flow_in_columns_and_screen_options_describe_each_widget(): void
    {
        $this->signIn($this->user(['use_dashed']));
        $page = $this->get(route('mainstay.admin'))->assertOk()
            ->assertSee('Counts the dashed things.')
            ->assertSee('columns-1', false)
            ->assertSee('break-inside-avoid', false);

        $this->assertStringNotContainsString('col-span-2', $page->getContent());
    }

    #[Test]
    public function any_screen_can_fill_the_options_slot_and_one_without_it_has_no_button(): void
    {
        $this->signIn($this->user());
        Route::middleware(Authenticate::class)->group(function () {
            Route::get('/admin-with-options', fn () => view('dashed::with-options'));
            Route::get('/admin-without-options', fn () => view('dashed::without-options'));
        });

        $this->get('/admin-with-options')->assertOk()
            ->assertSee('data-dialog-open="screen-options"', false)
            ->assertSee('Per page');
        $this->get('/admin-without-options')->assertOk()->assertDontSee('screen-options');
    }

    #[Test]
    public function every_shipped_widget_describes_itself(): void
    {
        foreach ([AtAGlance::class, ConnectorsWidget::class, McpWidget::class, SiteWidget::class, PagesWidget::class, PartsWidget::class, SubmissionsWidget::class] as $class) {
            $this->assertNotSame('', (new ReflectionClass($class))->newInstanceWithoutConstructor()->description(), $class);
        }
    }

    #[Test]
    public function screen_options_need_a_sign_in_and_the_forms_token(): void
    {
        $this->post(route('mainstay.dashboard.screen'), ['widgets' => ['host_note']])->assertRedirect(route('mainstay.login'));

        /* Outside tests, where Laravel's check would otherwise step aside. */
        $this->app['env'] = 'local';
        $this->signIn($this->user());
        $this->post(route('mainstay.dashboard.screen'), ['widgets' => ['host_note']])->assertStatus(419);
    }

    #[Test]
    public function at_a_glance_counts_what_the_user_may_read_and_links_to_its_list(): void
    {
        foreach (['one', 'two'] as $slug) {
            Mainstay::create(Post::class, data: ['title' => $slug, 'slug' => $slug, 'status' => 'live'], overrideAccess: true);
        }

        $this->signIn($this->user(['edit_entries']));
        $page = $this->get(route('mainstay.admin'))->assertOk();
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('mainstay.entries', 'post', absolute: false), '#').'"[^>]*>\s*<span[^>]*>2</span>#', $page->getContent());

        $this->signIn($this->user());
        Gate::policy(Media::class, ClosedPolicy::class);
        $this->get(route('mainstay.admin'))->assertOk()
            ->assertDontSee('href="'.route('mainstay.entries', 'post', absolute: false).'"', false)
            ->assertSee('There is nothing here you may read yet.');
    }

    #[Test]
    public function at_a_glance_counts_the_media_library_for_whoever_the_policy_lets_read_it(): void
    {
        $this->signIn($this->user());
        $this->get(route('mainstay.admin'))->assertOk()->assertSee('href="'.route('mainstay.media', absolute: false).'"', false);

        Gate::policy(Media::class, ClosedPolicy::class);
        $this->get(route('mainstay.admin'))->assertOk()->assertDontSee('href="'.route('mainstay.media', absolute: false).'"', false);
    }

    #[Test]
    public function preferences_are_read_and_set_by_dotted_key_and_saved(): void
    {
        $user = $this->user();
        $this->assertNull($user->preference('dashboard.widgets'));
        $this->assertSame('fallback', $user->preference('dashboard.widgets', 'fallback'));

        $user->setPreference('dashboard.widgets', ['host_note' => false]);
        $user->setPreference('editor.density', 'compact');

        $this->assertSame(['dashboard' => ['widgets' => ['host_note' => false]], 'editor' => ['density' => 'compact']], $user->fresh()->preferences);
        $this->assertFalse($user->fresh()->preference('dashboard.widgets.host_note'));
    }

    #[Test]
    public function the_users_table_has_a_preferences_column(): void
    {
        $this->assertTrue(Schema::hasColumn('mainstay_users', 'preferences'));
    }

    #[Test]
    public function a_widget_that_is_not_one_is_refused_with_what_to_do(): void
    {
        $this->refused(fn () => Mainstay::widgets([stdClass::class]), 'stdClass is not a dashboard widget. Extend Mainstay\Admin\Widget.');
        $this->refused(fn () => Mainstay::widgets([AbstractWidget::class]), AbstractWidget::class.' is abstract, so there is no widget to show. Name the class that extends it.');
        $this->refused(fn () => Mainstay::widgets([BadHandle::class]), BadHandle::class.' is called "Bad-Handle", and a widget\'s handle is lowercase letters, digits and underscores, starting with a letter.');
        $this->refused(fn () => Mainstay::widgets([42]), 'int is not a dashboard widget.');
    }

    #[Test]
    public function two_widgets_with_one_handle_are_refused_whoever_names_them(): void
    {
        $this->refused(fn () => Mainstay::widgets([DuplicateHandle::class]), 'Two dashboard widgets are called "dashed_metrics": '.DashedMetrics::class.' and '.DuplicateHandle::class);
        $this->refused(fn () => Mainstay::widgets([HostNote::class]), 'Two dashboard widgets are called "host_note"');
    }
}
