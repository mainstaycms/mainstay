<?php

namespace Mainstay\Forms\Tests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Mainstay\Auth\Role;
use Mainstay\Auth\User;
use Mainstay\Facades\Mainstay;
use Mainstay\Forms\Form;
use Mainstay\Forms\FormRules;
use Mainstay\Forms\Forms;
use Mainstay\Forms\FormsPlugin;
use Mainstay\Forms\Submission;
use Mainstay\Forms\SubmissionReceived;
use Mainstay\Forms\Tests\Fixtures\Landing;
use Mainstay\Http\AuthenticateToken;
use Mainstay\PageBuilder\PageBuilderPlugin;
use Mainstay\Tests\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/*
 | A form builder on the page builder: a form is an entry edited in the
 | Content tab from field blocks, its rules come from those blocks, and a post
 | to it, from a page or as JSON, is kept and then mailed.
 */
class FormsTest extends DatabaseTestCase
{
    private const POSTS_ALLOWED = 5;

    private int $made = 0;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mainstay.plugins', [PageBuilderPlugin::class, FormsPlugin::class]);
        $app['config']->set('mainstay.locales', ['en' => '/', 'nl' => '/nl']);
        $app['config']->set('mainstay.forms.limit', self::POSTS_ALLOWED);
        $app['view']->addLocation(__DIR__.'/Fixtures/views');
    }

    protected function defineRoutes($router): void
    {
        Route::middleware('web')->get('/contact/{form}', fn (int $form) => view('contact', ['form' => Mainstay::findById(Form::class, $form, overrideAccess: true)]));
    }

    protected function setUp(): void
    {
        parent::setUp();

        Mainstay::types([Landing::class]);
        $this->artisan('mainstay:sync')->assertSuccessful();
        $this->signIn($this->user(role: 'administrator'));
    }

    private function signIn(User $user): void
    {
        $this->flushSession();
        $this->actingAs($user, 'mainstay');
    }

    private function user(array $capabilities = [], ?string $role = null): User
    {
        $n = ++$this->made;
        $role = $role === null
            ? Role::query()->create(['name' => "role-{$n}", 'capabilities' => $capabilities])
            : Role::query()->where('name', $role)->sole();

        return User::query()->create(['name' => "Ada {$n}", 'email' => "ada-{$n}@example.com", 'password' => 'correct horse battery', 'role_id' => $role->id]);
    }

    private static function field(string $type, string $name, array $data = [], ?string $id = null): array
    {
        return ['type' => 'block', 'attrs' => ['id' => $id ?? "f-{$name}", 'type' => $type, 'data' => ['name' => $name, 'label' => ucfirst($name), ...$data]]];
    }

    private static function submit(): array
    {
        return ['type' => 'block', 'attrs' => ['id' => 'f-submit', 'type' => 'submit_button', 'data' => ['label' => 'Send it']]];
    }

    private static function paragraph(string $text): array
    {
        return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    private static function doc(array ...$nodes): array
    {
        return ['type' => 'doc', 'content' => $nodes];
    }

    /* A contact form: a required name and email, an optional message of at most 20 characters, a topic and a consent box. */
    private function contact(array $data = []): Form
    {
        return Mainstay::create(Form::class, [
            'title' => 'Contact',
            'recipients' => 'desk@example.com, boss@example.com',
            'content' => self::doc(
                self::paragraph('Write to us.'),
                self::field('text_input', 'name', ['required' => true]),
                self::field('email_input', 'email', ['required' => true]),
                self::field('textarea', 'message', ['maxLength' => 20]),
                self::field('select', 'topic', ['required' => true, 'options' => "Sales\nSupport"]),
                self::field('checkbox', 'consent'),
                self::submit(),
            ),
            ...$data,
        ], locale: 'en', overrideAccess: true);
    }

    private static function valid(array $data = []): array
    {
        return ['name' => 'Ada', 'email' => 'ada@example.com', 'message' => 'Hello', 'topic' => 'Sales', 'consent' => '1', ...$data];
    }

    private function postPage(Form $form, array $data): TestResponse
    {
        return $this->withSession(['_token' => 'page'])->from('/contact/'.$form->id)->post(route('mainstay.forms.submit', $form->id), ['_token' => 'page', ...$data]);
    }

    #[Test]
    public function a_form_is_an_entry_whose_content_is_field_blocks_between_text(): void
    {
        $form = Mainstay::findById(Form::class, $this->contact()->id, locale: 'en', overrideAccess: true);

        $this->assertSame(['text_input', 'email_input', 'textarea', 'select', 'checkbox', 'submit_button'], array_map(fn ($block) => $block::handle(), $form->content->blocks()));
        $this->assertStringContainsString('<p>Write to us.</p>', $form->content->toHtml());
        $this->assertSame('desk@example.com, boss@example.com', $form->recipients);
    }

    #[Test]
    public function recipients_must_be_email_addresses(): void
    {
        $this->assertThrows(fn () => $this->contact(['recipients' => 'desk@example.com, nobody']), ValidationException::class, 'nobody');
        $this->assertSame(0, DB::table('form')->count());
    }

    #[Test]
    public function a_redirect_must_be_a_web_address(): void
    {
        $this->assertThrows(fn () => $this->contact(['redirect' => 'javascript:alert(1)']), ValidationException::class);
        $this->assertSame('https://example.com/thanks', $this->contact(['redirect' => 'https://example.com/thanks'])->redirect);
    }

    #[Test]
    public function rules_come_from_the_blocks_the_form_holds(): void
    {
        $rules = FormRules::for(Mainstay::findById(Form::class, $this->contact()->id, locale: 'en', overrideAccess: true));

        $this->assertSame(['name', 'email', 'message', 'topic', 'consent'], array_keys($rules));
        $this->assertSame(['required', 'string', 'max:255'], $rules['name']);
        $this->assertSame(['required', 'string', 'email', 'max:255'], $rules['email']);
        $this->assertSame(['nullable', 'string', 'max:20'], $rules['message']);
        $this->assertSame(['required', 'string', 'in:"Sales","Support"'], array_map(strval(...), $rules['topic']));
        $this->assertSame(['nullable', 'boolean'], $rules['consent']);
    }

    #[Test]
    public function a_required_checkbox_must_be_ticked(): void
    {
        $form = $this->contact(['content' => self::doc(self::field('checkbox', 'terms', ['required' => true]))]);

        $this->assertSame(['terms' => ['accepted']], FormRules::for(Mainstay::findById(Form::class, $form->id, locale: 'en', overrideAccess: true)));
    }

    #[Test]
    public function a_repeated_or_malformed_field_name_is_refused_where_the_block_is(): void
    {
        $repeated = fn () => $this->contact(['content' => self::doc(self::field('text_input', 'name'), self::paragraph('Between'), self::field('email_input', 'name', id: 'f-again'))]);

        try {
            $repeated();
            $this->fail('A name used twice was saved.');
        } catch (ValidationException $exception) {
            $this->assertSame(['content.content.2.attrs.data.name'], array_keys($exception->errors()));
            $this->assertStringContainsString('already has a field called name', $exception->errors()['content.content.2.attrs.data.name'][0]);
        }

        foreach (['Not Valid', '9lives', '_token', 'a-b'] as $name) {
            try {
                $this->contact(['content' => self::doc(self::field('text_input', $name))]);
                $this->fail("{$name} was saved as a field name.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('content.content.0.attrs.data.name', $exception->errors());
            }
        }

        $this->assertSame(0, DB::table('form')->count());
    }

    #[Test]
    public function a_form_is_edited_on_the_content_tab_in_a_canvas_of_its_own(): void
    {
        $form = $this->contact();

        $this->get(route('mainstay.entries.edit', ['form', $form->id]))->assertOk()
            ->assertSee('id="content-tab"', escape: false)
            ->assertSee('data-canvas-url="'.route('mainstay.canvas.entry', ['form', $form->id]).'"', escape: false)
            ->assertSee('data-insert="block:text_input"', escape: false)
            ->assertSee('data-insert="block:submit_button"', escape: false)
            ->assertDontSee('data-insert="block:form"', escape: false);
        $this->get(route('mainstay.entries.create', 'form'))->assertOk()
            ->assertSee('data-canvas-url="'.route('mainstay.canvas.create', 'form').'"', escape: false);
    }

    #[Test]
    public function the_canvas_draws_the_content_alone_and_the_block_endpoint_checks_a_field(): void
    {
        $form = $this->contact();
        $content = json_encode(self::doc(self::field('text_input', 'city'), self::paragraph('Typed')));

        $page = $this->post(route('mainstay.canvas.entry', ['form', $form->id]), ['content' => $content])->assertOk();
        $this->assertStringContainsString('<main class="">', $page->getContent());
        $this->assertStringContainsString('data-mainstay-content', $page->getContent());
        $this->assertStringContainsString('name=\"city\"', $page->getContent(), 'The block is drawn in the document the canvas is sent.');
        $this->post(route('mainstay.canvas.create', 'form'), ['content' => $content])->assertOk();

        $this->post(route('mainstay.block.entry', ['form', $form->id]), ['block' => json_encode(self::field('text_input', 'city')['attrs'])])->assertOk()
            ->assertJsonPath('block.id', 'f-city')
            ->assertJsonPath('html', fn (string $html) => str_contains($html, '<label for="field-f-city">City</label>'));
        $this->post(route('mainstay.block.entry', ['form', $form->id]), ['block' => json_encode(self::field('text_input', 'Bad name')['attrs'])])->assertStatus(422);
    }

    #[Test]
    public function the_canvas_and_block_endpoints_answer_only_whoever_may_edit_forms(): void
    {
        $form = $this->contact();
        $content = ['content' => json_encode(self::doc(self::paragraph('Hi')))];
        $block = ['block' => json_encode(self::field('text_input', 'city')['attrs'])];

        $this->signIn($this->user(['edit_forms', 'edit_published_forms', 'edit_others_forms']));
        $this->post(route('mainstay.canvas.entry', ['form', $form->id]), $content)->assertOk();
        $this->post(route('mainstay.block.entry', ['form', $form->id]), $block)->assertOk();

        $this->signIn($this->user(['edit_submissions']));
        $this->post(route('mainstay.canvas.entry', ['form', $form->id]), $content)->assertForbidden();
        $this->post(route('mainstay.block.entry', ['form', $form->id]), $block)->assertForbidden();
    }

    /* The items under each heading of the sidebar, as the dashboard draws it. */
    private function sidebar(): array
    {
        $page = $this->get(route('mainstay.admin'))->assertOk()->getContent();
        preg_match_all('#<h2[^>]*>([^<]+)</h2>\s*<ul[^>]*>(.*?)</ul>#s', $page, $sections, PREG_SET_ORDER);

        return collect($sections)->mapWithKeys(fn (array $section) => [trim($section[1]) => array_values(array_filter(array_map(fn (string $item) => trim(preg_replace('/\s+/', ' ', strip_tags($item))), preg_split('#(?=<li)#', $section[2]))))])->all();
    }

    #[Test]
    public function the_sidebar_lists_forms_under_a_heading_of_their_own_and_submissions_with_the_content(): void
    {
        $sidebar = $this->sidebar();

        $this->assertSame(['Forms'], $sidebar['Forms']);
        $this->assertSame(['Landings'], $sidebar['Landings']);
        $this->assertSame(['Submissions'], $sidebar['Content']);

        $this->signIn($this->user(['edit_forms']));
        $this->assertSame(['Forms'], $this->sidebar()['Forms']);
        $this->assertArrayNotHasKey('Content', $this->sidebar());

        $this->signIn($this->user(['edit_submissions']));
        $this->assertSame(['Submissions'], $this->sidebar()['Content'], 'Whoever reads submissions is shown them although none may be written.');
        $this->assertArrayNotHasKey('Forms', $this->sidebar());
    }

    #[Test]
    public function a_page_post_is_kept_then_mailed_and_sent_back_with_the_confirmation(): void
    {
        Mail::fake();
        $form = $this->contact();

        $this->postPage($form, self::valid())->assertRedirect('/contact/'.$form->id)->assertSessionHas(Forms::ACCEPTED, $form->id);

        $submission = Mainstay::find(Submission::class, locale: 'en', overrideAccess: true)->sole();
        $this->assertSame($form->id, $submission->form->id);
        $this->assertSame('en', $submission->language);
        $this->assertSame(
            [['name' => 'name', 'label' => 'Name', 'value' => 'Ada'], ['name' => 'email', 'label' => 'Email', 'value' => 'ada@example.com'], ['name' => 'message', 'label' => 'Message', 'value' => 'Hello'], ['name' => 'topic', 'label' => 'Topic', 'value' => 'Sales'], ['name' => 'consent', 'label' => 'Consent', 'value' => true]],
            $submission->answers,
        );
        Mail::assertSent(SubmissionReceived::class, function (SubmissionReceived $mail) {
            $mail->assertSeeInHtml('<dt>Email</dt>', false);
            $mail->assertSeeInText('Email: ada@example.com');

            return $mail->hasTo('desk@example.com') && $mail->hasTo('boss@example.com') && $mail->hasSubject('New submission: Contact');
        });
    }

    #[Test]
    public function the_confirmation_is_shown_in_place_of_the_form_on_the_page_it_came_back_to(): void
    {
        Mail::fake();
        $form = $this->contact(['message' => self::doc(self::paragraph('We will be in touch.'))]);

        $page = $this->followingRedirects()->postPage($form, self::valid());

        $page->assertSee('<p>We will be in touch.</p>', escape: false)->assertDontSee('<form', escape: false);
        $this->get('/contact/'.$form->id)->assertSee('<form', escape: false);

        $plain = $this->contact(['title' => 'Plain']);
        $this->followingRedirects()->postPage($plain, self::valid())->assertSee('Thank you.');
    }

    #[Test]
    public function a_form_with_a_redirect_sends_the_submitter_on_instead(): void
    {
        Mail::fake();
        $form = $this->contact(['redirect' => 'https://example.com/thanks']);

        $this->postPage($form, self::valid())->assertRedirect('https://example.com/thanks')->assertSessionMissing(Forms::ACCEPTED);
        $this->assertSame(1, DB::table('submission')->count());
    }

    #[Test]
    public function a_refused_page_post_comes_back_with_the_errors_and_what_was_typed_and_keeps_nothing(): void
    {
        Mail::fake();
        $form = $this->contact();

        $this->postPage($form, self::valid(['email' => 'nobody', 'topic' => 'Other', 'name' => '', 'message' => str_repeat('x', 21)]))
            ->assertRedirect('/contact/'.$form->id)
            ->assertSessionHasErrors(['name', 'email', 'topic', 'message'])
            ->assertSessionHasInput('email', 'nobody');

        $this->assertSame(0, DB::table('submission')->count());
        Mail::assertNothingSent();

        $page = $this->followingRedirects()->postPage($form, self::valid(['email' => 'nobody', 'name' => 'Ada']));
        $page->assertSee('value="nobody"', escape: false)->assertSee('value="Ada"', escape: false)->assertSee('role="alert"', escape: false);
        $this->assertStringContainsString('The Email field must be a valid email address.', html_entity_decode($page->getContent()));
    }

    #[Test]
    public function a_post_without_the_pages_token_is_refused(): void
    {
        /* Outside tests, where Laravel's check would otherwise step aside. */
        $this->app['env'] = 'local';
        Mail::fake();
        $form = $this->contact();

        $this->withSession(['_token' => 'page'])->post(route('mainstay.forms.submit', $form->id), self::valid())->assertStatus(419);
        $this->withSession(['_token' => 'page'])->post(route('mainstay.forms.submit', $form->id), ['_token' => 'other', ...self::valid()])->assertStatus(419);
        $this->assertSame(0, DB::table('submission')->count());

        $this->postPage($form, self::valid())->assertSessionHas(Forms::ACCEPTED);
    }

    #[Test]
    public function only_a_live_form_takes_a_post(): void
    {
        $draft = Mainstay::drafts()->save(Form::class, ['title' => 'Soon', 'content' => self::doc(self::field('text_input', 'name'))], locale: 'en', overrideAccess: true);
        $live = $this->contact();
        $trashed = $this->contact(['title' => 'Gone']);
        Mainstay::delete(Form::class, $trashed->id, overrideAccess: true);
        Mainstay::drafts()->save(Form::class, ['content' => self::doc(self::field('text_input', 'other'))], entry: $live->id, locale: 'en', overrideAccess: true);

        $this->assertNull($draft->entryId);
        $this->postPage($live, self::valid())->assertSessionHasNoErrors()->assertSessionHas(Forms::ACCEPTED);

        $this->postPage($trashed, self::valid())->assertNotFound();
        $this->postJson(route('mainstay.api.forms.submit', 9999), self::valid())->assertNotFound();
        $this->assertSame(1, DB::table('submission')->count(), 'A pending draft changes nothing live: the live form took the post, the others none.');
        $this->postJson(route('mainstay.api.forms.submit', $trashed->id), self::valid())->assertNotFound();
    }

    #[Test]
    public function a_form_published_in_one_language_is_not_live_in_another(): void
    {
        $form = $this->contact();

        $this->postJson(route('mainstay.api.forms.submit', $form->id).'?locale=nl', self::valid())->assertNotFound();
        $this->postJson(route('mainstay.api.forms.submit', $form->id).'?locale=en', self::valid())->assertCreated();
    }

    #[Test]
    public function the_json_endpoint_takes_a_post_without_a_token(): void
    {
        Mail::fake();
        $form = $this->contact(['message' => self::doc(self::paragraph('Thanks!'))]);

        $this->postJson(route('mainstay.api.forms.submit', $form->id), self::valid())
            ->assertCreated()
            ->assertExactJson(['message' => '<p>Thanks!</p>', 'redirect' => null]);

        $this->assertSame(1, DB::table('submission')->count());
        Mail::assertSent(SubmissionReceived::class, 1);
        $this->assertStringEndsWith("/forms/{$form->id}/submissions", route('mainstay.api.forms.submit', $form->id));
        $this->assertContains('api', Route::getRoutes()->getByName('mainstay.api.forms.submit')->gatherMiddleware());
        $this->assertNotContains(AuthenticateToken::class, Route::getRoutes()->getByName('mainstay.api.forms.submit')->gatherMiddleware());
    }

    #[Test]
    public function the_json_endpoint_answers_422_with_the_errors_and_keeps_nothing(): void
    {
        Mail::fake();
        $form = $this->contact();

        $this->postJson(route('mainstay.api.forms.submit', $form->id), self::valid(['email' => 'nobody', 'topic' => 'Other']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'topic']);
        $this->postJson(route('mainstay.api.forms.submit', $form->id), [])->assertStatus(422)->assertJsonValidationErrors(['name', 'email', 'topic']);

        $this->assertSame(0, DB::table('submission')->count());
        Mail::assertNothingSent();
    }

    #[Test]
    public function the_json_endpoint_sends_the_forms_redirect_for_the_front_end_to_follow(): void
    {
        Mail::fake();
        $form = $this->contact(['redirect' => 'https://example.com/thanks']);

        $this->postJson(route('mainstay.api.forms.submit', $form->id), self::valid())->assertCreated()->assertJsonPath('redirect', 'https://example.com/thanks');
    }

    #[Test]
    public function both_endpoints_stop_after_the_limit_from_one_address(): void
    {
        Mail::fake();
        $form = $this->contact();

        for ($sent = 1; $sent <= self::POSTS_ALLOWED; $sent++) {
            $this->postJson(route('mainstay.api.forms.submit', $form->id), self::valid())->assertCreated();
        }

        $this->postJson(route('mainstay.api.forms.submit', $form->id), self::valid())->assertStatus(429);
        $this->postPage($form, self::valid())->assertStatus(429);
        $this->assertSame(self::POSTS_ALLOWED, DB::table('submission')->count());
    }

    #[Test]
    public function a_mail_that_fails_is_reported_and_costs_the_submission_and_the_visitor_nothing(): void
    {
        Exceptions::fake();
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP is down'));
        $form = $this->contact();

        $this->postJson(route('mainstay.api.forms.submit', $form->id), self::valid())->assertCreated();
        $this->postPage($form, self::valid())->assertSessionHas(Forms::ACCEPTED);

        $this->assertSame(2, DB::table('submission')->count());
        Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'SMTP is down');
    }

    #[Test]
    public function the_submission_is_stored_before_the_mail_leaves(): void
    {
        config(['mail.default' => 'array']);
        $stored = [];
        Event::listen(MessageSending::class, function () use (&$stored) {
            $stored[] = DB::table('submission')->count();
        });
        $form = $this->contact();

        $this->postJson(route('mainstay.api.forms.submit', $form->id), self::valid())->assertCreated();

        $this->assertSame([1], $stored, 'One mail, sent once the submission was in the table.');
    }

    #[Test]
    public function a_form_of_nothing_but_a_button_keeps_a_submission_with_no_answers(): void
    {
        Mail::fake();
        $form = $this->contact(['content' => self::doc(self::submit())]);

        $this->postJson(route('mainstay.api.forms.submit', $form->id), [])->assertCreated();

        $this->assertSame([], Mainstay::find(Submission::class, locale: 'en', overrideAccess: true)->sole()->answers);
    }

    #[Test]
    public function a_form_without_recipients_is_kept_and_mails_no_one(): void
    {
        Mail::fake();
        $form = $this->contact(['recipients' => null]);

        $this->postJson(route('mainstay.api.forms.submit', $form->id), self::valid())->assertCreated();

        $this->assertSame(1, DB::table('submission')->count());
        Mail::assertNothingSent();
    }

    #[Test]
    public function nobody_makes_or_changes_a_submission_by_hand_and_a_reader_may_view_and_delete_one(): void
    {
        $form = $this->contact();
        $make = fn () => Mainstay::create(Submission::class, ['title' => 'By hand', 'form' => $form->id, 'answers' => [], 'language' => 'en'], locale: 'en');
        $kept = Mainstay::create(Submission::class, ['title' => 'Sent', 'form' => $form->id, 'answers' => [['name' => 'name', 'label' => 'Name', 'value' => 'Ada']], 'language' => 'en'], locale: 'en', overrideAccess: true);

        foreach ([$this->user(role: 'administrator'), $this->user(['edit_submissions', 'publish_submissions', 'edit_published_submissions', 'edit_others_submissions', 'delete_submissions', 'delete_others_submissions'])] as $user) {
            $this->signIn($user);

            $this->assertThrows($make, AuthorizationException::class, 'read and deleted, never written');
            $this->assertThrows(fn () => Mainstay::update(Submission::class, $kept->id, ['language' => 'nl'], locale: 'en'), AuthorizationException::class, 'read and deleted, never written');
            $this->assertSame('Sent', Mainstay::findById(Submission::class, $kept->id, locale: 'en')->title);
        }

        $this->get(route('mainstay.entries', 'submission'))->assertOk()->assertDontSee('New submission');
        $this->get(route('mainstay.entries.edit', ['submission', $kept->id]))->assertOk()->assertSee('Ada');
        $this->post(route('mainstay.entries.store', 'submission'), ['title' => 'By hand'])->assertForbidden();
        $this->post(route('mainstay.entries.update', ['submission', $kept->id]), ['title' => 'Changed'])->assertForbidden();
        $this->assertSame('Sent', DB::table('submission_locales')->value('title'));

        Mainstay::delete(Submission::class, $kept->id);
        $this->assertSame(0, Mainstay::find(Submission::class, locale: 'en')->count());
    }

    #[Test]
    public function the_api_refuses_to_write_a_submission_and_lets_a_token_read_and_delete_one(): void
    {
        $form = $this->contact();
        $kept = Mainstay::create(Submission::class, ['title' => 'Sent', 'form' => $form->id, 'answers' => [['name' => 'name', 'label' => 'Name', 'value' => 'Ada']], 'language' => 'en'], locale: 'en', overrideAccess: true);
        $headers = ['Authorization' => 'Bearer '.Mainstay::tokens()->issue($this->user(role: 'administrator')->id, 'Build', ['*'], overrideAccess: true)[1]];
        $api = '/'.config('mainstay.api.prefix').'/entries/submission';

        $this->getJson($api.'/'.$kept->id, $headers)->assertOk()->assertJsonPath('title', 'Sent');
        $this->postJson($api, ['data' => ['title' => 'By hand', 'form' => $form->id, 'answers' => [['name' => 'name', 'label' => 'Name', 'value' => 'Ada']], 'language' => 'en']], $headers)->assertForbidden();
        $this->patchJson($api.'/'.$kept->id, ['data' => ['language' => 'nl']], $headers)->assertForbidden();
        $this->deleteJson($api.'/'.$kept->id, headers: $headers)->assertNoContent();
    }

    #[Test]
    public function a_view_prints_a_form_with_its_token_its_fields_and_nothing_for_an_editor(): void
    {
        $form = $this->contact();

        $html = $this->get('/contact/'.$form->id)->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<form method="post" action="http://localhost/mainstay/forms/'.$form->id.'" enctype="application/x-www-form-urlencoded">\s*<input type="hidden" name="_token" value="[^"]+"#', $html);
        $this->assertStringContainsString('<p>Write to us.</p>', $html);
        $this->assertStringContainsString('<label for="field-f-name">Name</label>', $html);
        $this->assertStringContainsString('<input type="email" id="field-f-email" name="email"', $html);
        $this->assertStringContainsString('<option value="Support"', $html);
        $this->assertStringContainsString('maxlength="20"', $html);
        $this->assertStringContainsString('<button type="submit">Send it</button>', $html);
        $this->assertStringNotContainsString('data-mainstay', $html);
        $this->assertStringNotContainsString('desk@example.com', $html, 'Who is mailed is not the page\'s business.');
    }

    #[Test]
    public function a_form_that_is_not_live_prints_nothing(): void
    {
        $form = $this->contact();
        Mainstay::delete(Form::class, $form->id, overrideAccess: true);

        $this->assertSame('', (string) $this->app->make(Forms::class)->print(null));
        $this->assertSame('', (string) $this->app->make(Forms::class)->print(Form::reference($form->id)));
        $this->assertSame("<main></main>\n", $this->get('/contact/'.$form->id)->getContent());
    }

    #[Test]
    public function the_form_block_prints_the_form_a_page_picks_and_nothing_once_it_is_not_live(): void
    {
        $form = $this->contact();
        $pick = ['type' => 'block', 'attrs' => ['id' => 'pick', 'type' => 'form', 'data' => ['form' => $form->id]]];
        $landing = Mainstay::create(Landing::class, ['title' => 'Home', 'content' => self::doc(self::paragraph('Before'), $pick)], locale: 'en', overrideAccess: true);

        $html = Mainstay::findById(Landing::class, $landing->id, locale: 'en')->content->toHtml();

        $this->assertStringContainsString('<p>Before</p>', $html);
        $this->assertStringContainsString('action="http://localhost/mainstay/forms/'.$form->id.'"', $html);
        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringNotContainsString('desk@example.com', $html);

        Mainstay::delete(Form::class, $form->id, overrideAccess: true);

        $this->assertStringNotContainsString('<form', Mainstay::findById(Landing::class, $landing->id, locale: 'en')->content->toHtml());
    }

    #[Test]
    public function the_form_block_is_offered_only_where_a_type_lists_it(): void
    {
        $landing = Mainstay::create(Landing::class, ['title' => 'Home'], locale: 'en', overrideAccess: true);

        $this->get(route('mainstay.entries.edit', ['landing', $landing->id]))->assertOk()
            ->assertSee('data-insert="block:form"', escape: false)
            ->assertDontSee('data-insert="block:text_input"', escape: false);
        $this->get(route('mainstay.entries.create', 'form'))->assertOk()->assertDontSee('data-insert="block:form"', escape: false);
    }
}
