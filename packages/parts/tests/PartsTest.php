<?php

namespace Mainstay\Parts\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Mainstay\Auth\Role;
use Mainstay\Auth\User;
use Mainstay\Content\Media;
use Mainstay\Facades\Mainstay;
use Mainstay\Http\Form;
use Mainstay\PageBuilder\PageBuilderPlugin;
use Mainstay\Parts\Parts;
use Mainstay\Parts\PartsPlugin;
use Mainstay\Parts\Tests\Fixtures\Addressed;
use Mainstay\Parts\Tests\Fixtures\Footer;
use Mainstay\Parts\Tests\Fixtures\Marked;
use Mainstay\Parts\Tests\Fixtures\Menu;
use Mainstay\Parts\Tests\Fixtures\Note;
use Mainstay\Parts\Tests\Fixtures\Tagged;
use Mainstay\Site\SitePlugin;
use Mainstay\Tests\DatabaseTestCase;
use Mainstay\Tests\Fixtures\Broken\BlockedSettings;
use Mainstay\Tests\Fixtures\Broken\Contented;
use Mainstay\Tests\Fixtures\Broken\Pageless;
use Mainstay\Tests\Fixtures\SiteSettings;
use PHPUnit\Framework\Attributes\Test;

/*
 | A part: a global that holds content built from blocks and has no address,
 | stored, drafted and published as a global is, printed by a view, listed in
 | the admin apart from the globals, and edited on the Content tab.
 */
class PartsTest extends DatabaseTestCase
{
    private int $made = 0;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mainstay.plugins', [SitePlugin::class, PageBuilderPlugin::class, PartsPlugin::class]);
        $app['config']->set('mainstay.locales', ['en' => '/', 'nl' => '/nl']);
        $app['view']->addLocation(__DIR__.'/Fixtures/views');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Mainstay::types([Footer::class, Menu::class, Note::class, SiteSettings::class]);
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

    private function logo(): Media
    {
        Storage::fake('public');
        Storage::fake('local');

        return Mainstay::media()->upload(UploadedFile::fake()->image('logo.png', 40, 30), ['en' => 'Our logo', 'nl' => 'Ons logo'], overrideAccess: true);
    }

    private static function brand(string $name, ?int $logo = null): array
    {
        return ['type' => 'block', 'attrs' => ['id' => 'b-'.str($name)->slug(), 'type' => 'brand', 'data' => ['name' => $name, 'logo' => $logo]]];
    }

    private static function legal(string $line): array
    {
        return ['type' => 'block', 'attrs' => ['id' => 'l-'.str($line)->slug(), 'type' => 'legal', 'data' => ['line' => $line]]];
    }

    private static function paragraph(string $text): array
    {
        return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    private static function doc(array ...$nodes): array
    {
        return ['type' => 'doc', 'content' => $nodes];
    }

    #[Test]
    public function a_part_is_stored_once_per_site_localized_and_read_back_with_its_blocks_images(): void
    {
        $logo = $this->logo();

        Mainstay::saveGlobal(Footer::class, ['content' => self::doc(self::brand('Acme', $logo->id), self::paragraph('Hello'))], locale: 'en', overrideAccess: true);
        Mainstay::saveGlobal(Footer::class, ['content' => self::doc(self::legal('Alle rechten voorbehouden'))], locale: 'nl', overrideAccess: true);
        Mainstay::saveGlobal(Footer::class, ['content' => self::doc(self::brand('Acme', $logo->id), self::paragraph('Hello again'))], locale: 'en', overrideAccess: true);

        $this->assertSame(1, DB::table('footer')->count(), 'One row for the site.');
        $this->assertSame(2, DB::table('footer_locales')->count(), 'A row for each language.');

        $english = Mainstay::global(Footer::class, locale: 'en', overrideAccess: true);
        $brand = $english->content->blocks()[0];

        $this->assertSame(['brand'], array_map(fn ($block) => $block::handle(), $english->content->blocks()));
        $this->assertFalse($brand->logo->missing, 'Its image is loaded with it.');
        $this->assertSame('Our logo', $brand->logo->alt);
        $this->assertSame(['legal'], array_map(fn ($block) => $block::handle(), Mainstay::global(Footer::class, locale: 'nl', overrideAccess: true)->content->blocks()));
    }

    #[Test]
    public function a_part_is_drafted_and_published_as_a_global_is(): void
    {
        Mainstay::saveGlobal(Footer::class, ['content' => self::doc(self::legal('Live'))], locale: 'en', overrideAccess: true);

        $draft = Mainstay::drafts()->save(Footer::class, ['content' => self::doc(self::legal('Drafted'))], locale: 'en', overrideAccess: true);

        $this->assertSame('Live', Mainstay::global(Footer::class, locale: 'en', overrideAccess: true)->content->blocks()[0]->line, 'A draft changes nothing live.');
        $this->assertSame('Drafted', Mainstay::drafts()->of(Footer::class, locale: 'en', overrideAccess: true)->entry->content->blocks()[0]->line);

        Mainstay::drafts()->publish($draft->id, overrideAccess: true);

        $this->assertSame('Drafted', Mainstay::global(Footer::class, locale: 'en', overrideAccess: true)->content->blocks()[0]->line);
        $this->assertSame(1, DB::table('footer')->count());
    }

    #[Test]
    public function a_view_prints_a_part_with_its_blocks_in_order_through_their_views_and_its_text_escaped(): void
    {
        $this->assertSame('<footer></footer>', trim(view('layout')->render()), 'Nothing where the part is not written.');

        $logo = $this->logo();
        Mainstay::saveGlobal(Footer::class, ['content' => self::doc(self::paragraph('1 < 2 & <b>more</b>'), self::brand('<i>Acme</i>', $logo->id), self::legal('All rights'))], locale: 'en', overrideAccess: true);
        Mainstay::saveGlobal(Footer::class, ['content' => self::doc(self::legal('Alle rechten'))], locale: 'nl', overrideAccess: true);

        $html = view('layout')->render();

        $this->assertStringContainsString('<p>1 &lt; 2 &amp; &lt;b&gt;more&lt;/b&gt;</p>', $html);
        $this->assertStringContainsString('<a class="brand"><img alt="Our logo">&lt;i&gt;Acme&lt;/i&gt;</a>', $html);
        $this->assertStringNotContainsString('data-mainstay', $html, 'Nothing marked for an editor on the site.');
        $this->assertMatchesRegularExpression('#<p>1.*<a class="brand">.*<small>All rights</small>#s', $html, 'In the order they were written.');
        $this->assertSame($html, "<footer>{$this->app->make(Parts::class)->print(Footer::class)}</footer>\n");

        App::setLocale('nl');

        $this->assertStringContainsString('<small>Alle rechten</small>', view('layout')->render());
    }

    #[Test]
    public function a_draft_is_never_printed(): void
    {
        Mainstay::saveGlobal(Footer::class, ['content' => self::doc(self::legal('Live'))], locale: 'en', overrideAccess: true);
        Mainstay::drafts()->save(Footer::class, ['content' => self::doc(self::legal('Drafted'))], locale: 'en', overrideAccess: true);

        $html = $this->app->make(Parts::class)->print(Footer::class);

        $this->assertStringContainsString('Live', $html);
        $this->assertStringNotContainsString('Drafted', $html);
    }

    #[Test]
    public function an_ordinary_global_has_no_canvas_whoever_asks(): void
    {
        $handle = SiteSettings::handle();
        $content = ['content' => json_encode(self::doc(self::paragraph('Typed')))];

        $this->post(route('mainstay.canvas.global', $handle), $content)->assertNotFound();

        $this->signIn($this->user(['edit_footer']));
        $this->post(route('mainstay.canvas.global', $handle), $content)->assertNotFound();
        $this->post(route('mainstay.block.global', $handle), ['block' => '{}'])->assertNotFound();
    }

    #[Test]
    public function only_a_part_is_printed_as_one(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(SiteSettings::class.' is not a part');

        $this->app->make(Parts::class)->print(SiteSettings::class);
    }

    #[Test]
    public function a_part_takes_only_the_blocks_it_allows(): void
    {
        try {
            Mainstay::saveGlobal(Menu::class, ['content' => self::doc(self::brand('Acme'))], locale: 'en', overrideAccess: true);
            $this->fail('A block the part does not name was saved.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('content.content.0.attrs.type', $exception->errors());
        }

        $this->assertSame(0, DB::table('menu')->count());
    }

    #[Test]
    public function content_and_blocks_stay_refused_where_a_part_is_not_the_type(): void
    {
        foreach ([
            BlockedSettings::class => 'names blocks with #[Blocks], and only an entry type\'s content and a block hold them.',
            Contented::class => 'is a content field, and content is an entry\'s own, its $content.',
            Pageless::class => 'has no #[Route]: with no page there is nowhere to edit a block on',
            Marked::class => 'is edited as a page and has a #[Route]',
            Tagged::class => 'is edited as a page and is a taxonomy',
            Addressed::class => 'is edited as a page and has a #[Route]',
        ] as $type => $message) {
            $this->assertThrows(fn () => Mainstay::fields($type), InvalidArgumentException::class, $message);
        }
    }

    /* The items under each heading of the sidebar, as the dashboard draws it. */
    private function sidebar(): array
    {
        $page = $this->get(route('mainstay.admin'))->assertOk()->getContent();
        preg_match_all('#<h2[^>]*>([^<]+)</h2>\s*<ul[^>]*>(.*?)</ul>#s', $page, $sections, PREG_SET_ORDER);

        return collect($sections)->mapWithKeys(fn (array $section) => [trim($section[1]) => array_values(array_filter(array_map(fn (string $item) => trim(preg_replace('/\s+/', ' ', strip_tags($item))), preg_split('#(?=<li)#', $section[2]))))])->all();
    }

    #[Test]
    public function the_sidebar_lists_parts_under_a_heading_of_their_own_apart_from_the_globals(): void
    {
        $sidebar = $this->sidebar();

        $this->assertSame(['Footer', 'Menu'], $sidebar['Parts']);
        $this->assertSame(['Site Settings'], $sidebar['Globals']);
        $this->get(route('mainstay.admin'))->assertSee('href="'.route('mainstay.entries', 'footer', absolute: false).'"', escape: false);

        $this->signIn($this->user(['edit_footer']));
        $this->assertSame(['Footer'], $this->sidebar()['Parts'], 'Only the parts whoever it is may edit.');
        $this->assertArrayNotHasKey('Globals', $this->sidebar());

        $this->signIn($this->user(['edit_site_settings']));
        $this->assertArrayNotHasKey('Parts', $this->sidebar());
        $this->assertSame(['Site Settings'], $this->sidebar()['Globals']);
    }

    #[Test]
    public function the_edit_screen_has_the_content_tab_and_a_global_that_is_no_part_has_none(): void
    {
        $form = $this->get(route('mainstay.entries', 'footer'))->assertOk();
        $form
            ->assertSee('id="content-tab"', escape: false)
            ->assertSee('data-canvas-url="'.route('mainstay.canvas.global', 'footer').'"', escape: false)
            ->assertSee('data-insert="block:brand"', escape: false)
            ->assertSee('data-insert="block:legal"', escape: false)
            ->assertSee('Raw content');
        $this->assertLessThan(strpos($form->getContent(), 'id="details-tab"'), strpos($form->getContent(), 'id="content-tab"'), 'Alphabetical: Content before Details.');
        $this->get(route('mainstay.entries', 'menu'))->assertOk()->assertDontSee('data-insert="block:brand"', escape: false);
        $this->get(route('mainstay.entries', 'site_settings'))->assertOk()->assertDontSee('data-tabs', escape: false)->assertDontSee('data-canvas', escape: false);
    }

    #[Test]
    public function the_canvas_draws_the_content_alone_in_the_sites_editor_styles_and_writes_nothing(): void
    {
        Mainstay::editorStyles(['/css/site.css'], class: 'prose');
        Mainstay::saveGlobal(Footer::class, ['content' => self::doc(self::legal('Live'))], locale: 'en', overrideAccess: true);
        Mainstay::drafts()->save(Footer::class, ['content' => self::doc(self::legal('Drafted'))], locale: 'en', overrideAccess: true);
        $before = [DB::table('footer')->get(), DB::table('footer_locales')->get(), DB::table('mainstay_drafts')->get()];

        $page = $this->post(route('mainstay.canvas.global', 'footer'), ['content' => json_encode(self::doc(self::brand('Acme'), self::paragraph('Typed')))])->assertOk();

        $page->assertSee('<link rel="stylesheet" href="/css/site.css">', escape: false)
            ->assertSee('<main class="prose"><script type="application/json" data-mainstay-content>', escape: false);
        $this->assertStringContainsString('no-store', $page->headers->get('Cache-Control'));
        preg_match('#<script type="application/json" data-mainstay-content>(.*?)</script>#s', $page->getContent(), $script);
        $drawn = json_decode($script[1], true);
        $this->assertSame(['brand'], array_map(fn (array $node) => $node['attrs']['type'] ?? $node['type'], array_slice($drawn['doc']['content'], 0, 1)));
        $this->assertSame(['b-acme'], array_keys($drawn['blocks']));
        $this->assertStringContainsString('Acme</a>', $drawn['blocks']['b-acme']);

        /* The form posting no content draws the draft over what is live. */
        $this->assertStringContainsString('Drafted', $this->post(route('mainstay.canvas.global', 'footer'), [])->assertOk()->getContent());

        $this->assertEquals($before, [DB::table('footer')->get(), DB::table('footer_locales')->get(), DB::table('mainstay_drafts')->get()], 'Nothing written.');

        $this->post(route('mainstay.canvas.global', 'menu'), ['content' => json_encode(self::doc(self::brand('Acme')))])->assertStatus(422);
    }

    #[Test]
    public function a_block_is_drawn_and_checked_for_a_part_as_for_an_entry(): void
    {
        $block = ['id' => 'b-1', 'type' => 'legal', 'data' => ['line' => 'Hello']];

        $this->post(route('mainstay.block.global', 'footer'), ['block' => json_encode($block)])->assertOk()
            ->assertJsonPath('block.id', 'b-1')
            ->assertJsonPath('html', '<small><span data-mainstay-field="line" data-mainstay-item="b-1" data-mainstay-label="Line">Hello</span></small>'."\n");
        $this->post(route('mainstay.block.global', 'menu'), ['block' => json_encode(['id' => 'b-2', 'type' => 'brand', 'data' => ['name' => 'Acme']])])->assertStatus(422);
    }

    #[Test]
    public function the_endpoints_answer_a_part_to_whoever_may_edit_it_and_to_nobody_else(): void
    {
        $content = ['content' => json_encode(self::doc(self::paragraph('Hi')))];
        $block = ['block' => json_encode(['id' => 'b-1', 'type' => 'legal', 'data' => ['line' => 'Hello']])];

        $this->signIn($this->user(['edit_footer']));
        $this->post(route('mainstay.canvas.global', 'footer'), $content)->assertOk();
        $this->post(route('mainstay.block.global', 'footer'), $block)->assertOk();
        $this->get(route('mainstay.previews', 'footer').'?insert=paragraph')->assertOk();
        $this->post(route('mainstay.canvas.global', 'menu'), $content)->assertForbidden();
        $this->post(route('mainstay.block.global', 'menu'), $block)->assertForbidden();
        $this->get(route('mainstay.previews', 'menu').'?insert=paragraph')->assertForbidden();

        $this->signIn($this->user(['edit_site_settings']));
        $this->post(route('mainstay.canvas.global', 'footer'), $content)->assertForbidden();
        $this->post(route('mainstay.block.global', 'footer'), $block)->assertForbidden();
        $this->get(route('mainstay.previews', 'footer').'?insert=paragraph')->assertForbidden();
        $this->post(route('mainstay.canvas.global', 'site_settings'), $content)->assertNotFound();
        $this->post(route('mainstay.block.global', 'site_settings'), $block)->assertNotFound();

        $this->signIn($this->user());
        $this->post(route('mainstay.canvas.global', 'footer'), $content)->assertForbidden();
    }

    #[Test]
    public function an_entry_with_no_route_is_edited_as_a_page_under_a_heading_of_its_own(): void
    {
        $this->assertSame(['brand'], array_keys(Form::blocks(Note::class)));
        $this->assertSame(['Notes'], $this->sidebar()['Notes']);
        $this->assertArrayNotHasKey('Content', $this->sidebar());

        $note = Mainstay::create(Note::class, ['title' => 'Aside'], locale: 'en', overrideAccess: true);

        $this->get(route('mainstay.entries.edit', ['note', $note->id]))->assertOk()
            ->assertSee('data-canvas-url="'.route('mainstay.canvas.entry', ['note', $note->id]).'"', escape: false)
            ->assertSee('data-insert="block:brand"', escape: false);
        $this->post(route('mainstay.canvas.entry', ['note', $note->id]), ['content' => json_encode(self::doc(self::brand('Acme')))])->assertOk()
            ->assertSee('<main class="">', escape: false);
        $this->post(route('mainstay.canvas.create', 'note'), ['content' => json_encode(self::doc(self::paragraph('Typed')))])->assertOk();
    }
}
