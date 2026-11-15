<?php

namespace Mainstay\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use LogicException;
use Mainstay\Auth\Capabilities;
use Mainstay\Auth\Gate;
use Mainstay\Auth\Role;
use Mainstay\Auth\User;
use Mainstay\Facades\Mainstay;
use Mainstay\Navigation;
use Mainstay\Settings\Setting;
use Mainstay\Settings\Settings;
use Mainstay\Tests\Fixtures\Plugins\Configured;
use Mainstay\Tests\Fixtures\Plugins\Drawn;
use Mainstay\Tests\Fixtures\Plugins\Paged;
use Mainstay\Tests\Fixtures\Plugins\Tuned;
use Mainstay\Tests\Fixtures\Plugins\Twice;
use Mainstay\Tests\Fixtures\Plugins\Zebra;
use PHPUnit\Framework\Attributes\Test;

/*
 | The site's settings: what plugins declare, one stored value each, scoped
 | by the plugin's handle; the Settings screen that draws them for whoever
 | holds manage_settings, and writes only what it drew; a plugin's own
 | settings screen in the sidebar.
 */
class SettingsTest extends DatabaseTestCase
{
    private int $made = 0;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mainstay.plugins', [Zebra::class, Configured::class, Tuned::class, Paged::class, Drawn::class]);
    }

    private function signIn(array $capabilities): User
    {
        $role = Role::query()->create(['name' => 'role-'.++$this->made, 'capabilities' => $capabilities]);
        $user = User::query()->create(['name' => 'Ada', 'email' => "ada-{$this->made}@example.com", 'password' => 'correct horse battery', 'role_id' => $role->id]);
        $this->actingAs($user, 'mainstay');
        request()->attributes->set(Gate::USER, $user);

        return $user;
    }

    /** @param  array<string, mixed>  $configured */
    private function save(array $configured = [], array $tuned = []): TestResponse
    {
        return $this->post(route('mainstay.settings.update'), ['settings' => ['configured' => $configured, 'tuned' => $tuned]]);
    }

    #[Test]
    public function a_setting_holds_its_declared_default_until_one_is_set(): void
    {
        $this->assertSame('Untitled', Mainstay::setting('configured.title'));
        $this->assertSame('Fallback', Mainstay::setting('configured.blurb', 'Fallback'));
        $this->assertNull(Mainstay::setting('configured.limit'));
        $this->assertTrue(Mainstay::setting('configured.visible'));

        Mainstay::setSetting('configured.title', 'Hello');
        Mainstay::setSetting('configured.visible', false);
        Mainstay::setSetting('configured.limit', 2.5);

        $this->assertSame('Hello', Mainstay::setting('configured.title'));
        $this->assertFalse(Mainstay::setting('configured.visible'));
        $this->assertSame(2.5, Mainstay::setting('configured.limit'));
    }

    #[Test]
    public function a_value_is_stored_once_as_json_and_null_forgets_it(): void
    {
        Mainstay::setSetting('configured.title', 'One');
        Mainstay::setSetting('configured.title', 'Two');

        $this->assertSame(['"Two"'], DB::table('mainstay_settings')->pluck('value')->all());

        Mainstay::setSetting('configured.title', null);

        $this->assertSame(0, DB::table('mainstay_settings')->count());
        $this->assertSame('Untitled', Mainstay::setting('configured.title'));
    }

    #[Test]
    public function what_is_stored_is_read_once_per_request(): void
    {
        Mainstay::setSetting('configured.title', 'Cached');
        $this->app->forgetInstance(Settings::class);

        DB::enableQueryLog();
        Mainstay::setting('configured.title');
        Mainstay::setting('configured.blurb');
        Mainstay::setting('tuned.title');

        $this->assertCount(1, DB::getQueryLog());
    }

    #[Test]
    public function two_plugins_can_each_declare_a_title(): void
    {
        Mainstay::setSetting('configured.title', 'Mine');

        $this->assertSame('Mine', Mainstay::setting('configured.title'));
        $this->assertSame('Tuned', Mainstay::setting('tuned.title'));
    }

    #[Test]
    public function a_key_nobody_declared_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No plugin declares a setting called configured.nothing');

        Mainstay::setSetting('configured.nothing', 'x');
    }

    #[Test]
    public function a_plugin_declaring_a_key_twice_is_refused(): void
    {
        $this->app['config']->set('mainstay.plugins', [Twice::class]);
        $this->app->make(\Mainstay\Mainstay::class)->loadPlugins([Twice::class]);

        $this->expectException(LogicException::class);

        Mainstay::settings()->declared();
    }

    #[Test]
    public function a_declaration_is_checked_where_it_is_made(): void
    {
        foreach ([
            fn () => new Setting('Title', 'Title'),
            fn () => new Setting('title', 'Title', 'markdown'),
            fn () => new Setting('title', 'Title', 'select'),
            fn () => new Setting('title', 'Title', 'text', options: ['a' => 'A']),
            fn () => new Setting('title', 'Title', 'boolean', control: 'drawn::pick'),
        ] as $declare) {
            try {
                $declare();
                $this->fail('Expected a refusal.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function the_screen_draws_every_plugins_settings_under_its_label(): void
    {
        $this->signIn(['manage_settings', 'configure_secret']);

        $page = $this->get(route('mainstay.settings'))->assertOk();

        $page->assertSeeInOrder(['Configured', 'Title', 'Blurb', 'Visible', 'Limit', 'Tone', 'Secret', 'Tuned', 'Tuned title']);
        $page->assertSee('Shown above everything.')->assertSee('placeholder="Say something"', false)->assertSee('role="switch"', false);
        $page->assertSee('value="Untitled"', false)->assertSee('type="number"', false)->assertSee('<option value="warm"', false);
        $page->assertSee('name="settings[configured][title]"', false)->assertSee('name="settings[tuned][title]"', false);
    }

    #[Test]
    public function a_setting_can_draw_its_own_control_and_is_validated_as_text(): void
    {
        Mainstay::setSetting('drawn.pick', 'two');
        $this->signIn(['manage_settings']);

        $this->get(route('mainstay.settings'))->assertSee('data-drawn="pick"', false)->assertSee('name="settings[drawn][pick]"', false)->assertSee('value="two"', false);

        $this->from(route('mainstay.settings'))->post(route('mainstay.settings.update'), ['settings' => ['drawn' => ['pick' => 'far too long']]])->assertSessionHasErrors('settings.drawn.pick');
        $this->post(route('mainstay.settings.update'), ['settings' => ['drawn' => ['pick' => 'three']]])->assertSessionHasNoErrors();
        $this->assertSame('three', Mainstay::setting('drawn.pick'));
    }

    #[Test]
    public function the_screen_shows_what_is_stored(): void
    {
        Mainstay::setSetting('configured.blurb', "Line one\nLine two");
        Mainstay::setSetting('configured.tone', 'warm');
        $this->signIn(['manage_settings']);

        $this->get(route('mainstay.settings'))->assertSee("Line one\nLine two")->assertSee('<option value="warm" selected', false);
    }

    #[Test]
    public function saving_writes_every_type_and_flashes(): void
    {
        $this->signIn(['manage_settings']);

        $this->save(['title' => 'Hi', 'blurb' => "A\r\nB", 'visible' => '0', 'limit' => '3', 'tone' => 'warm'], ['title' => 'Other'])
            ->assertRedirect(route('mainstay.settings'))->assertSessionHas('status', 'Settings saved.');

        $this->assertSame('Hi', Mainstay::setting('configured.title'));
        $this->assertSame("A\nB", Mainstay::setting('configured.blurb'));
        $this->assertFalse(Mainstay::setting('configured.visible'));
        $this->assertSame(3, Mainstay::setting('configured.limit'));
        $this->assertSame('warm', Mainstay::setting('configured.tone'));
        $this->assertSame('Other', Mainstay::setting('tuned.title'));
    }

    #[Test]
    public function a_refused_answer_is_shown_beside_its_field_and_nothing_is_saved(): void
    {
        $this->signIn(['manage_settings']);

        $this->from(route('mainstay.settings'))->save(['title' => 'Far too long a title', 'limit' => '0', 'tone' => 'loud'], ['title' => 'Kept?'])
            ->assertRedirect(route('mainstay.settings'))
            ->assertSessionHasErrors(['settings.configured.title', 'settings.configured.limit', 'settings.configured.tone']);

        $this->assertSame(0, DB::table('mainstay_settings')->count());

        $this->followingRedirects()->from(route('mainstay.settings'))->save(['title' => 'Far too long a title'])
            ->assertSee('id="setting-settings-configured-title-error"', false)->assertSee('Far too long a title');
    }

    #[Test]
    public function a_name_that_was_not_drawn_is_not_written(): void
    {
        $this->signIn(['manage_settings']);

        $this->save(['title' => 'Hi', 'secret' => 'leaked', 'nothing' => 'x'])->assertSessionHasNoErrors();

        $this->assertSame('Hi', Mainstay::setting('configured.title'));
        $this->assertNull(Mainstay::setting('configured.secret'));
        $this->assertSame(1, DB::table('mainstay_settings')->count());
    }

    #[Test]
    public function a_setting_asking_for_a_capability_is_drawn_and_written_for_whoever_holds_it(): void
    {
        $this->signIn(['manage_settings', 'configure_secret']);

        $this->get(route('mainstay.settings'))->assertSee('name="settings[configured][secret]"', false);
        $this->save(['secret' => 'kept'])->assertSessionHasNoErrors();

        $this->assertSame('kept', Mainstay::setting('configured.secret'));
    }

    #[Test]
    public function one_plugins_key_is_not_written_through_anothers(): void
    {
        $this->signIn(['manage_settings']);

        $this->post(route('mainstay.settings.update'), ['settings' => ['tuned' => ['title' => 'Via tuned'], 'configured' => ['limit' => '4']]])->assertSessionHasNoErrors();

        $this->assertSame('Untitled', Mainstay::setting('configured.title'));
        $this->assertSame('Via tuned', Mainstay::setting('tuned.title'));
        $this->assertSame(4, Mainstay::setting('configured.limit'));

        $this->post(route('mainstay.settings.update'), ['settings' => ['configured' => ['tuned.title' => 'x'], 'tuned' => ['limit' => '9']]]);

        $this->assertSame('Via tuned', Mainstay::setting('tuned.title'));
        $this->assertNull(Mainstay::setting('tuned.limit'));
    }

    #[Test]
    public function without_manage_settings_the_screen_and_every_write_are_not_there(): void
    {
        $this->signIn(['configure_secret']);

        $this->get(route('mainstay.settings'))->assertNotFound();
        $this->save(['title' => 'Nope'])->assertNotFound();

        $this->assertSame(0, DB::table('mainstay_settings')->count());
    }

    #[Test]
    public function a_user_without_a_settings_capability_does_not_see_or_write_it(): void
    {
        $this->signIn(['manage_settings']);

        $this->get(route('mainstay.settings'))->assertOk()->assertDontSee('Secret')->assertSee('Title');
        $this->save(['secret' => 'Nope', 'title' => 'Fine']);

        $this->assertNull(Mainstay::setting('configured.secret'));
        $this->assertSame('Fine', Mainstay::setting('configured.title'));
    }

    #[Test]
    public function nobody_signed_in_is_sent_to_sign_in(): void
    {
        $this->get(route('mainstay.settings'))->assertRedirect();
        $this->post(route('mainstay.settings.update'), ['settings' => []])->assertRedirect();
    }

    #[Test]
    public function the_sidebar_has_one_settings_item_and_none_for_a_plugins_screens(): void
    {
        $this->signIn(['manage_settings']);

        $items = collect(Navigation::sections())->flatMap(fn (array $section) => $section['items'])->pluck('href');

        $this->assertSame(['/admin/settings'], $items->filter(fn (string $href) => str_contains($href, 'settings'))->values()->all());
        $this->assertNotContains('/admin/settings/paged', $items->all());
        $this->assertNull(collect(Navigation::sections())->first(fn (array $section) => ($section['label'] ?? null) === 'Settings'));
    }

    #[Test]
    public function the_sidebar_has_no_settings_for_whoever_cannot_manage_them(): void
    {
        $this->signIn(['configure_secret']);

        $this->get(route('mainstay.admin'))->assertDontSee('/admin/settings', false);
    }

    #[Test]
    public function the_settings_navigation_lists_general_then_each_plugins_screen_by_label(): void
    {
        $this->signIn(['manage_settings']);

        $this->assertSame(['General', 'Paged', 'Zebra'], array_column(Navigation::settingsScreens(), 'label'));

        $page = $this->get(route('mainstay.settings'))->assertOk();
        $page->assertSeeInOrder(['aria-label="Settings"', 'General', 'Paged']);
        $page->assertSee('href="/admin/settings/paged"', false)->assertSee('aria-current="page"', false);
    }

    #[Test]
    public function a_plugins_own_screen_is_a_child_of_settings_with_the_navigation_beside_it(): void
    {
        $this->signIn(['manage_settings']);

        $page = $this->get('/admin/settings/paged')->assertOk()->assertSee('Paged settings');
        $page->assertSee('href="/admin/settings"', false)->assertSeeInOrder(['href="/admin/settings/paged"', 'aria-current="page"'], false);
        $this->assertSame('mainstay.settings.paged.index', Route::getRoutes()->match(Request::create('/admin/settings/paged'))->getName());
    }

    #[Test]
    public function a_plugins_own_screen_is_not_there_without_manage_settings(): void
    {
        $this->signIn(['configure_secret']);

        $this->get('/admin/settings/paged')->assertNotFound();
        $this->get('/admin/paged/settings')->assertNotFound();
        $this->assertSame([], Navigation::settingsScreens());
    }

    #[Test]
    public function manage_settings_and_a_settings_own_capability_are_ones_a_role_can_hold(): void
    {
        $this->assertContains('manage_settings', Mainstay::capabilities());
        $this->assertContains('configure_secret', Mainstay::capabilities());
        $this->assertContains('configure_secret', Capabilities::settings());

        $this->signIn(['*']);
        $this->get(route('mainstay.roles.create'))->assertSee('value="manage_settings"', false)->assertSee('value="configure_secret"', false)->assertSee('Configured: Secret');
    }

    #[Test]
    public function a_number_that_is_not_finite_is_refused_not_stored(): void
    {
        $this->signIn(['manage_settings']);

        $this->from(route('mainstay.settings'))->save(['limit' => '1e999'])->assertSessionHasErrors('settings.configured.limit');

        $this->assertSame(0, DB::table('mainstay_settings')->count());
    }

    #[Test]
    public function a_length_rule_counts_a_browsers_line_ends_as_one_character(): void
    {
        $this->signIn(['manage_settings']);
        $twelve = "abcde\r\nfghi\r\nj";

        $this->assertSame(12, strlen(str_replace("\r\n", "\n", $twelve)));
        $this->save(['title' => $twelve])->assertSessionHasNoErrors();
        $this->assertSame("abcde\nfghi\nj", Mainstay::setting('configured.title'));

        $this->save(['title' => $twelve.'k'])->assertSessionHasErrors('settings.configured.title');
    }
}
