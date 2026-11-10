<?php

namespace Mainstay\Forms\Tests;

use Mainstay\Auth\Role;
use Mainstay\Auth\User;
use Mainstay\Facades\Mainstay;
use Mainstay\Forms\Form;
use Mainstay\Forms\FormsPlugin;
use Mainstay\Forms\Submission;
use Mainstay\PageBuilder\PageBuilderPlugin;
use Mainstay\Tests\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Test;

/* The Submissions card: the total and the newest, for whoever reads submissions. */
class SubmissionsWidgetTest extends DatabaseTestCase
{
    private const SHOWN = 5;

    private int $made = 0;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mainstay.plugins', [PageBuilderPlugin::class, FormsPlugin::class]);
        $app['config']->set('mainstay.locales', ['en' => '/', 'nl' => '/nl']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('mainstay:sync')->assertSuccessful();
    }

    private function signIn(array $capabilities): void
    {
        $n = ++$this->made;
        $role = Role::query()->create(['name' => "role-{$n}", 'capabilities' => $capabilities]);
        $user = User::query()->create(['name' => "Ada {$n}", 'email' => "ada-{$n}@example.com", 'password' => 'correct horse battery', 'role_id' => $role->id]);
        $this->flushSession();
        $this->actingAs($user, 'mainstay');
    }

    private function form(): Form
    {
        return Mainstay::create(Form::class, ['title' => 'Contact', 'recipients' => ''], locale: 'en', overrideAccess: true);
    }

    private function submission(Form $form, mixed $value, string $locale = 'en'): Submission
    {
        return Mainstay::create(Submission::class, ['title' => 'Contact', 'form' => $form->id, 'answers' => [['name' => 'name', 'label' => 'Name', 'value' => $value]], 'language' => $locale], locale: $locale, overrideAccess: true);
    }

    #[Test]
    public function it_says_there_are_none_when_nothing_was_sent(): void
    {
        $this->signIn(['edit_submissions']);

        $this->get(route('mainstay.admin'))->assertOk()
            ->assertSee('id="widget-forms"', false)
            ->assertSee('<span class="block text-2xl font-medium">0</span>', false);
    }

    #[Test]
    public function it_shows_the_total_and_the_newest_with_their_first_answer_escaped_and_cut(): void
    {
        $form = $this->form();
        $this->submission($form, '<script>alert(1)</script>');
        $this->submission($form, str_repeat('long ', 40));

        foreach (range(1, self::SHOWN) as $n) {
            $this->submission($form, "answer {$n}", 'nl');
        }

        $this->signIn(['edit_submissions']);
        $newest = Mainstay::find(Submission::class, sort: '-createdAt', locale: 'nl', overrideAccess: true)->first();
        $page = $this->get(route('mainstay.admin'))->assertOk()
            ->assertSee('<span class="block text-2xl font-medium">7</span>', false)
            ->assertSee('href="'.route('mainstay.entries.edit', ['submission', $newest->id], absolute: false).'"', false)
            ->assertSee('href="'.route('mainstay.entries', 'submission', absolute: false).'"', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('href="'.route('mainstay.entries', 'form', absolute: false).'"', false);

        $this->assertSame(self::SHOWN, substr_count($page->getContent(), '>Contact</a>'));
        $this->assertStringNotContainsString(str_repeat('long ', 40), $page->getContent());
    }

    #[Test]
    public function it_links_to_forms_for_whoever_may_edit_them(): void
    {
        $this->signIn(['edit_submissions', 'edit_forms']);

        $this->get(route('mainstay.admin'))->assertOk()
            ->assertSee('href="'.route('mainstay.entries', 'form', absolute: false).'"', false);
    }

    #[Test]
    public function it_is_neither_shown_nor_offered_to_someone_who_cannot_read_submissions(): void
    {
        $this->submission($this->form(), 'private');

        $this->signIn(['edit_forms']);
        $this->get(route('mainstay.admin'))->assertOk()
            ->assertDontSee('id="widget-forms"', false)
            ->assertDontSee('private');

        $this->post(route('mainstay.dashboard.screen'), ['widgets' => ['forms']])->assertRedirect();
        $this->assertArrayNotHasKey('forms', auth('mainstay')->user()->fresh()->preference('dashboard.widgets'));
    }
}
