<?php

namespace Mainstay\PageBuilder\Tests;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mainstay\Auth\Gate;
use Mainstay\Auth\Role;
use Mainstay\Auth\User;
use Mainstay\Content\Icon;
use Mainstay\Facades\Mainstay;
use Mainstay\Fields\Blocks;
use Mainstay\Http\Form;
use Mainstay\PageBuilder\Http\PreviewController;
use Mainstay\PageBuilder\PageBuilderPlugin;
use Mainstay\Site\SitePlugin;
use Mainstay\Tests\DatabaseTestCase;
use Mainstay\Tests\Fixtures\Blocks\Looped;
use Mainstay\Tests\Fixtures\Blocks\Slide;
use Mainstay\Tests\Fixtures\Guide;
use Mainstay\Tests\Fixtures\Leaflet;
use Mainstay\Tests\Fixtures\Linked\Genre;
use Mainstay\Tests\Fixtures\Linked\Person;
use Mainstay\Tests\Fixtures\Linked\Story;
use Mainstay\Tests\Fixtures\Pictured;
use Mainstay\Tests\Fixtures\Post;
use Mainstay\Tests\Fixtures\Signed\Banner;
use Mainstay\Tests\Fixtures\Signed\Flyer;
use Mainstay\Tests\Fixtures\Signed\Note;
use Mainstay\Tests\Fixtures\Signed\Topic;
use Mainstay\Tests\Fixtures\Spread;
use PHPUnit\Framework\Attributes\Test;

/*
 | The Content tab over HTTP: the page the site draws from the form as it
 | stands, a site block's settings and the Add block dialog's previews, all
 | written to nothing, and the tab there only for a type with a page.
 */
class PageBuilderTest extends DatabaseTestCase
{
    private int $made = 0;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mainstay.plugins', [SitePlugin::class, PageBuilderPlugin::class]);
        $app['config']->set('mainstay.locales', ['en' => '/', 'nl' => '/nl']);
        $app['view']->addLocation(__DIR__.'/../../cms/tests/Fixtures/views');
    }

    protected function setUp(): void
    {
        parent::setUp();

        /* Not declare(), which swaps in a registry that has loaded no plugin. */
        Mainstay::types([Post::class, Note::class, Flyer::class, Topic::class, Banner::class, Guide::class, Person::class, Story::class, Genre::class, Pictured::class, Leaflet::class, Spread::class]);
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

    /* What a form drew as each field's fingerprint, and when its draft was
       saved, as a browser would post them back. */
    private function drawn(TestResponse $response): array
    {
        preg_match_all('/name="_seen\[(\w+)\]" value="(\w+)"/', $response->getContent(), $seen, PREG_SET_ORDER);
        preg_match('/name="_draft_at" value="([^"]*)"/', $response->getContent(), $at);

        return ['_seen' => array_column($seen, 2, 1), '_draft_at' => html_entity_decode($at[1] ?? '')];
    }

    private function values(array $fields = []): array
    {
        return ['title' => 'Hello', 'slug' => 'hello', 'status' => 'live', 'publishedAt' => '2026-10-07T14:30:00+02:00', 'featured' => '0', 'readingMinutes' => '4', 'template' => 'post', ...$fields];
    }

    /* A document of one paragraph, and of whatever nodes it is given. */
    private static function said(string $text, array ...$nodes): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]], ...$nodes]];
    }

    /*
     | What the rest of the request sees once a page is drawn, as the
     | innermost of the admin's middleware, inside its session's: who is
     | signed in to Mainstay, the default guard, the container's guard's user
     | -- resolved before the draw, as a host's code might -- and the locale.
     */
    private function after(): object
    {
        $after = new class
        {
            public array $seen = [];

            public function handle($request, $next)
            {
                app('auth.driver');
                $response = $next($request);
                $this->seen = [Gate::user()?->name, Auth::getDefaultDriver(), app('auth.driver')->user()?->name, app()->getLocale()];

                return $response;
            }
        };

        $this->app->instance('canvas.after', $after);
        $this->app['router']->pushMiddlewareToGroup('mainstay', 'canvas.after');

        return $after;
    }

    /* What a spread's content prints for the editor, decoded. */
    private static function scripted(TestResponse $page): array
    {
        preg_match('#<script type="application/json" data-mainstay-content>(.*?)</script>#s', $page->getContent(), $script);

        return json_decode($script[1] ?? 'null', true);
    }

    #[Test]
    public function the_content_tab_and_its_script_are_on_a_routed_types_form_and_the_endpoints_refuse_whoever_may_not_write(): void
    {
        $form = $this->get(route('mainstay.entries.create', 'post'))->assertOk();
        $form
            ->assertSee('id="content-tab"', escape: false)
            ->assertSee('vendor/page_builder/page-builder.js?v=', escape: false)
            ->assertSee('vendor/page_builder/page-builder.css?v=', escape: false);
        $this->assertLessThan(strpos($form->getContent(), 'id="details-tab"'), strpos($form->getContent(), 'id="content-tab"'), 'Alphabetical: Content before Details.');

        $this->signIn($this->user(['edit_posts']));
        $this->post(route('mainstay.canvas.create', 'spread'), ['title' => 'Mine'])->assertForbidden();
        $this->post(route('mainstay.block.create', 'spread'), [])->assertForbidden();
        $this->get(route('mainstay.previews', 'spread').'?insert=paragraph')->assertForbidden();

        auth('mainstay')->logout();
        $this->flushSession();
        $this->post(route('mainstay.canvas.create', 'post'), $this->values())->assertRedirect(route('mainstay.login'));
    }

    #[Test]
    public function its_built_files_are_published_with_the_admins_under_one_tag(): void
    {
        $published = ServiceProvider::pathsToPublish(null, 'mainstay-assets');

        $this->assertContains(public_path('vendor/page_builder'), $published);
        $this->assertFileExists(__DIR__.'/../dist/page-builder.js');
        $this->assertFileExists(__DIR__.'/../dist/page-builder.css');
    }

    #[Test]
    public function the_content_tab_draws_the_page_from_the_form_as_it_stands_and_writes_nothing(): void
    {
        $noir = Mainstay::create(Genre::class, ['title' => 'Noir', 'slug' => 'noir'], locale: 'en', overrideAccess: true);
        $spread = Mainstay::create(Spread::class, ['title' => 'First', 'slug' => 'first', 'issuedOn' => '2026-10-07', 'memo' => 'Hidden', 'content' => self::said('Live text')], locale: 'en', overrideAccess: true);
        $other = Mainstay::create(Spread::class, ['title' => 'Other', 'slug' => 'other', 'issuedOn' => '2026-10-08', 'memo' => 'Other memo', 'content' => self::said('Other text')], locale: 'en', overrideAccess: true);
        Mainstay::drafts()->save(Spread::class, ['title' => 'Other, drafted'], entry: $other->id, locale: 'en', overrideAccess: true);
        Mainstay::drafts()->save(Spread::class, ['title' => 'Drafted', 'aside' => self::said('Beside')], entry: $spread->id, locale: 'en', overrideAccess: true);

        $canvas = route('mainstay.canvas.entry', ['spread', $spread->id]);
        $form = $this->get(route('mainstay.entries.edit', ['spread', $spread->id]))->assertOk()
            ->assertSee('data-tabs="entry"', escape: false)
            ->assertSee('data-canvas-url="'.$canvas.'"', escape: false)
            ->assertSee('data-insert="block:callout"', escape: false)
            ->assertSee('data-canvas-tree', escape: false);
        $this->get(route('mainstay.entries.create', 'leaflet'))->assertDontSee('data-tabs', escape: false)->assertDontSee('data-canvas-tree', escape: false);
        $this->assertSame(1, substr_count($form->getContent(), 'id="mainstay-link"'), 'One link dialog for the aside and the content alike.');
        $this->get(route('mainstay.entries.create', 'post'))->assertSee('data-canvas', escape: false)->assertSee('id="mainstay-link"', escape: false);

        $content = self::said('Typed </script><script>alert(1)</script> & here', ['type' => 'block', 'attrs' => ['id' => 'b1', 'type' => 'callout', 'data' => ['heading' => 'Mind the gap']]]);
        $stored = fn () => array_map(fn (string $table) => DB::table($table)->orderBy('id')->get()->map(fn (object $row) => (array) $row)->all(), ['spread', 'spread_locales', 'genre', 'genre_entries', 'mainstay_drafts']);
        $before = $stored();
        $after = $this->after();

        /* The form as it stands: a title typed, a tag picked and one typed,
           the content as the editor wrote it. The draft's aside under it,
           and what is live under that. */
        $page = $this->post($canvas, [...$this->drawn($form), 'title' => 'Typed', 'slug' => 'first', 'issuedOn' => '2026-10-07', 'genres' => ['', "id:{$noir->id}", 'new:Fresh'], 'content' => json_encode($content)])->assertOk();
        $page->assertSeeInOrder(['<h1>Typed</h1>', "id {$spread->id}, http://localhost/spreads/first, issued 2026-10-07", 'no memo', 'a guest', 'to its guard too', '<li>Noir</li>', '<aside><p>Beside</p></aside>', '<section>Other: <p>Other text</p></section>'], escape: false);
        $page->assertDontSee('Fresh')->assertDontSee('Other, drafted')->assertDontSee('Other memo');
        $this->assertSame(['Ada 1', 'mainstay', 'Ada 1', 'en'], $after->seen, 'Signed in again once it is drawn, in the admin\'s locale.');

        /* The content alone is printed for the editor: its document and each
           block's HTML by id, a `</script>` typed in it kept inside. */
        $this->assertStringContainsString('<main class="prose"><script type="application/json" data-mainstay-content>', $page->getContent());
        $this->assertStringContainsString('no-store', $page->headers->get('Cache-Control'), 'Drawn from a form, never kept.');
        $this->assertStringNotContainsString('<script>alert(1)', $page->getContent());
        $this->assertStringContainsString('\u003C/script\u003E\u003Cscript\u003Ealert(1)\u003C/script\u003E \u0026 here', $page->getContent());
        $scripted = self::scripted($page);
        $content['content'][1]['attrs']['data'] += ['text' => null, 'on' => null, 'tone' => 'info'];
        $this->assertEquals($content, $scripted['doc'], 'As it would be saved: its defaults filled in.');
        $this->assertSame(['b1' => '<aside class="info"><h2><span data-mainstay-field="heading" data-mainstay-item="b1" data-mainstay-label="Heading">Mind the gap</span></h2></aside>'], array_map(trim(...), $scripted['blocks']), 'Its text marked for typing in place.');
        $this->assertSame($before, $stored(), 'Nothing written: no tag made, no draft saved.');

        /* The public page draws its content as the site does. */
        $this->get('/spreads/first')->assertOk()->assertSee('<main class="prose"><p>Live text</p></main>', escape: false)->assertDontSee('data-mainstay-content', escape: false);
    }

    #[Test]
    public function the_content_tab_draws_a_new_entry_and_says_why_a_page_cannot_be_drawn(): void
    {
        $values = ['title' => 'New', 'slug' => 'new', 'issuedOn' => '2026-10-07', 'content' => ''];

        /* A new one, no id and no address, its empty content an empty
           document; one not published yet, from its draft. */
        $page = $this->post(route('mainstay.canvas.create', 'spread'), $values)->assertOk()->assertSee('id none, no address');
        $this->assertSame(['doc' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]], 'blocks' => []], self::scripted($page));
        $this->post(route('mainstay.entries.store', 'spread'), $values)->assertSessionHasNoErrors();
        $draft = Mainstay::drafts()->all(Spread::class, locale: 'en', overrideAccess: true)->sole();
        $form = $this->get(route('mainstay.drafts.edit', ['spread', $draft->id]))->assertSee('data-canvas-url="'.route('mainstay.canvas.draft', ['spread', $draft->id]).'"', escape: false);
        $this->post(route('mainstay.canvas.draft', ['spread', $draft->id]), [...$this->drawn($form), 'title' => 'Newer'])->assertOk()->assertSee('<h1>Newer</h1>', escape: false);

        /* A date the page needs left empty, a value of the wrong kind, and a
           view that throws, each said rather than drawn. */
        $this->post(route('mainstay.canvas.create', 'spread'), [...$values, 'issuedOn' => ''])->assertStatus(422)->assertExactJson(['messages' => ['The page is drawn once it has Issued On.']]);
        $this->post(route('mainstay.canvas.create', 'spread'), [...$values, 'issuedOn' => 'soon'])->assertStatus(422)->assertJsonPath('messages.0', 'The issued on field must be a valid date.');
        $this->assertStringStartsWith('The view broke here.', $this->post(route('mainstay.canvas.create', 'spread'), [...$values, 'slug' => 'broken'])->assertStatus(500)->json('messages.0'));

        /* Refused to whoever may not change it, as its save would be, and no
           canvas for a type with no page. */
        $live = Mainstay::create(Spread::class, ['title' => 'Theirs', 'slug' => 'theirs', 'issuedOn' => '2026-10-07'], locale: 'en', overrideAccess: true);
        $this->signIn($this->user(['edit_spreads', 'edit_published_spreads']));
        $this->post(route('mainstay.canvas.entry', ['spread', $live->id]), ['title' => 'Mine'])->assertForbidden()->assertJsonPath('messages.0', 'This needs edit_others_spreads.');
        $this->signIn($this->user(role: 'administrator'));
        $this->post(route('mainstay.canvas.create', 'leaflet'), ['title' => 'Flat'])->assertNotFound();
        $this->post(route('mainstay.canvas.draft', ['genre', 1]), ['title' => 'Noir'])->assertNotFound()->assertJsonStructure(['messages']);

        /* In the form's locale, and in the template it names; the admin's
           own locale after. */
        $after = $this->after();
        $this->post(route('mainstay.canvas.create', ['post', 'locale' => 'nl']), $this->values())->assertOk()->assertSee('locale nl');
        $this->assertSame('en', $after->seen[3]);
        $this->post(route('mainstay.canvas.create', 'post'), $this->values(['template' => 'special']))->assertOk()->assertSee('special: Hello');
    }

    /* A spread whose content holds a gallery holding one slide, and the
       address its edit screen posts a block to. */
    private function galleried(): array
    {
        $gallery = ['id' => 'g1', 'type' => 'gallery', 'data' => ['caption' => 'Shots']];
        $slide = ['id' => 's1', 'type' => 'slide', 'data' => ['title' => 'One', 'weight' => 2, 'shown' => true]];
        $spread = Mainstay::create(Spread::class, ['title' => 'First', 'slug' => 'first', 'issuedOn' => '2026-10-07', 'content' => self::said('Text', self::holding($gallery, $slide))], locale: 'en', overrideAccess: true);

        return [$spread, $gallery, $slide, route('mainstay.block.entry', ['spread', $spread->id])];
    }

    /* A site block node holding these blocks inside it. */
    private static function holding(array $block, array ...$inner): array
    {
        return ['type' => 'block', 'attrs' => $block, 'content' => array_map(fn (array $each) => ['type' => 'block', 'attrs' => $each], $inner)];
    }

    #[Test]
    public function the_add_block_dialog_says_what_each_block_is_for_and_previews_it_from_its_example(): void
    {
        Mainstay::editorStyles(['/css/site.css'], class: 'prose');
        [$spread] = $this->galleried();
        $previews = route('mainstay.previews', 'spread');

        /* A card for each block, saying what it is for; a site block with no
           example has no preview, text always has one. */
        $html = $this->get(route('mainstay.entries.edit', ['spread', $spread->id]))->assertOk()->getContent();
        $this->assertStringContainsString('class="text-sm text-muted">Pictures in a row, each one a slide.</p>', $html);
        $this->assertStringContainsString('class="text-sm text-muted">A note set apart from the text.</p>', $html);
        $this->assertStringContainsString('src="'.e($previews.'?insert=block%3Agallery').'"', $html);
        $this->assertStringContainsString('src="'.e($previews.'?insert=paragraph').'"', $html);
        $this->assertStringNotContainsString('?insert=block%3Acallout', $html);

        /* Each kind in the `/` menu by its icon: a block's own, the plain
           block's for one with none, and text's built in. A card with no
           preview shows the icon in its place. */
        $this->assertStringContainsString('data-insert="block:gallery" class="flex w-full items-center gap-2 rounded-control px-3 py-1.5 text-left text-sm text-ink hover:bg-surface focus-visible:bg-surface focus-visible:outline-none">'.(new Icon('gallery'))->toHtml().'<span>Gallery</span>', $html);
        $this->assertStringContainsString('data-insert="block:callout" class="flex w-full items-center gap-2 rounded-control px-3 py-1.5 text-left text-sm text-ink hover:bg-surface focus-visible:bg-surface focus-visible:outline-none">'.(new Icon('block'))->toHtml().'<span>Callout</span>', $html);
        $this->assertStringContainsString('data-insert="blockquote" class="flex w-full items-center gap-2 rounded-control px-3 py-1.5 text-left text-sm text-ink hover:bg-surface focus-visible:bg-surface focus-visible:outline-none">'.(new Icon('quote'))->toHtml().'<span>Quote</span>', $html);
        $this->assertMatchesRegularExpression('/<li data-insert="block:callout"[^>]*>\s*<div aria-hidden="true" class="grid h-40 place-items-center[^"]*">'.preg_quote((new Icon('block'))->toHtml(), '/').'<\/div>/', $html);

        /* Both dialogs as big as what is in them, up to the screen, what is
           in them inside their borders: no scrollbar of the dialog's own. */
        $this->assertMatchesRegularExpression('/<dialog id="canvas-inserter"[^>]* class="m-auto max-h-\[calc\(100dvh-3rem\)\] w-\[calc\(100%-3rem\)\] max-w-\[77rem\] [^"]*">\s*<div class="flex max-h-\[calc\(100dvh-3rem-2px\)\] flex-col">/', $html);
        $this->assertMatchesRegularExpression('/<dialog aria-labelledby="[^"]*-label" class="m-auto max-h-\[calc\(100dvh-3rem\)\] w-\[calc\(100%-3rem\)\] max-w-3xl [^"]*">\s*<form id="[^"]*" data-canvas-sidebar aria-labelledby="[^"]*" class="flex max-h-\[calc\(100dvh-3rem-2px\)\] flex-col">/', $html);

        /* A site block drawn from its example as a visitor sees it, holding
           one of each block inside it that has one, in the site's styles. */
        $this->get($previews.'?insert=block:gallery')->assertOk()
            ->assertSee('<link rel="stylesheet" href="/css/site.css">', escape: false)
            ->assertSeeInOrder(['<div class="prose" style="padding: 32px"><figure><p>First light</p>', '<figcaption>Harbour at dawn</figcaption></figure>'], escape: false)
            ->assertDontSee('signed in');

        /* One whose example says what is inside it: text, and a block from
           its own. */
        $this->get($previews.'?insert=block:panel')->assertOk()->assertSee("<section><h2>Opening hours</h2><p>First light</p>\n<p>Closed on Mondays.</p></section>", escape: false);

        /* One holding its own kind is drawn holding none of it, which would
           never end. */
        $this->assertSame([], (fn () => $this->sample(Looped::class))->call(app(PreviewController::class))->innerContent()->toArray()['content']);

        /* Text from a sample. */
        $this->get($previews.'?insert=bullet_list')->assertOk()->assertSee('<ul><li><p>One thing</p></li><li><p>Another thing</p></li>', escape: false);

        foreach (['block:callout', 'block:nope', 'nope', ''] as $insert) {
            $this->get($previews.'?insert='.urlencode($insert))->assertNotFound();
        }

        $this->get(route('mainstay.previews', 'leaflet').'?insert=paragraph')->assertNotFound();
    }

    #[Test]
    public function a_site_block_is_drawn_with_its_settings_changed_there_and_refused_as_its_save_would_be(): void
    {
        [$spread, $gallery, $slide, $block] = $this->galleried();
        $inside = ['ancestors' => json_encode([$gallery])];

        /* The edit screen: the block route, the settings a form beside the
           entry's rather than in it, every block the content holds with the
           blocks it holds inside it, and the dialogs an image in a block
           needs. */
        $html = $this->get(route('mainstay.entries.edit', ['spread', $spread->id]))->assertOk()->assertSee('data-block-url="'.$block.'"', escape: false)->getContent();
        $this->assertStringContainsString('</form>', Str::between($html, 'id="entry-form"', 'id="mainstay-block"'));
        $this->assertSame(1, substr_count($html, 'id="mainstay-picker"'), 'The picker an image in a block needs, once.');
        $this->assertSame(1, substr_count($html, 'id="mainstay-image"'), 'And the dialog it is edited in.');
        preg_match('/data-blocks="([^"]*)"/', $html, $blocks);
        preg_match('/data-top="([^"]*)"/', $html, $top);
        $blocks = json_decode(html_entity_decode($blocks[1]), true);
        $this->assertSame([['label' => 'Gallery', 'inner' => ['slide'], 'text' => false], ['label' => 'Slide', 'inner' => [], 'text' => false], ['label' => 'Panel', 'inner' => ['slide'], 'text' => true]], [$blocks['gallery'], $blocks['slide'], $blocks['panel']]);
        $this->assertSame(['callout', 'gallery', 'figure', 'shelf', 'panel'], json_decode(html_entity_decode($top[1]), true));
        $draft = Mainstay::drafts()->save(Spread::class, ['title' => 'Drafted', 'slug' => 'drafted', 'issuedOn' => '2026-10-07'], locale: 'en', overrideAccess: true);
        $this->get(route('mainstay.drafts.edit', ['spread', $draft->id]))->assertSee('data-block-url="'.route('mainstay.block.draft', ['spread', $draft->id]).'"', escape: false);
        $this->post(route('mainstay.block.draft', ['spread', $draft->id]), ['block' => json_encode($gallery)])->assertOk();
        Mainstay::drafts()->discard($draft->id, overrideAccess: true);
        $this->get(route('mainstay.entries.create', 'spread'))->assertSee('data-block-url="'.route('mainstay.block.create', 'spread').'"', escape: false);

        /* Selected: drawn as the canvas draws it -- a block holding others
           marking where they go, each drawn on its own -- and its settings,
           every field by the component Details draws its kind with, the text
           marked as on the page. */
        $selected = $this->post($block, ['block' => json_encode($gallery)])->assertOk();
        $this->assertStringContainsString('<figure><template data-mainstay-inner></template><figcaption><span data-mainstay-field="caption" data-mainstay-item="g1" data-mainstay-label="Caption">Shots</span></figcaption>', $selected->json('html'));
        $this->assertStringNotContainsString('signed in', $selected->json('html'), 'Drawn as a visitor sees it.');
        $this->assertEquals($gallery, $selected->json('block'));
        foreach (['name="id" value="g1"', 'name="type" value="gallery"', 'name="data[caption]"', 'value="Shots"', 'data-mainstay-item="g1"'] as $drawn) {
            $this->assertStringContainsString($drawn, $selected->json('form'));
        }

        $this->assertStringNotContainsString('slide', $selected->json('form'), 'The blocks inside it are no field of it.');

        $inner = $this->post($block, ['block' => json_encode($slide), ...$inside])->assertOk();
        $this->assertSame('<p><span data-mainstay-field="title" data-mainstay-item="s1" data-mainstay-label="Title">One</span></p>', trim($inner->json('html')));
        $this->assertEquals($slide, $inner->json('block'));
        foreach (['name="id" value="s1"', 'name="data[title]"', 'value="One"', 'data-mainstay-item="s1"', 'data-mainstay-field="title"', 'name="data[weight]"'] as $drawn) {
            $this->assertStringContainsString($drawn, $inner->json('form'));
        }

        /* Changed there: each field through its own conversion, the block
           drawn again from it, and the settings left as they are typed in.
           A block given a blank id is given one. */
        $posted = ['id' => 's1', 'type' => 'slide', 'data' => ['title' => 'Uno', 'weight' => '2.5', 'shown' => '1'], ...$inside];
        $changed = $this->post($block, $posted)->assertOk();
        $this->assertEquals(['id' => 's1', 'type' => 'slide', 'data' => ['title' => 'Uno', 'weight' => 2.5, 'shown' => true]], $changed->json('block'));
        $this->assertStringContainsString('data-mainstay-item="s1" data-mainstay-label="Title">Uno</span>', $changed->json('html'));
        $this->assertNull($changed->json('form'));
        $this->assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', $this->post($block, [...$posted, 'id' => ''])->assertOk()->json('block.id'));

        /* Only where the block around it holds it. */
        $this->post($block, ['block' => json_encode($slide)])->assertStatus(422)->assertExactJson(['messages' => ['The content holds no block of the type "slide" here.']]);
        $this->post($block, [...$posted, 'ancestors' => json_encode([['type' => 'callout', 'data' => []]])])->assertStatus(422);
        $this->post($block, [...$posted, 'ancestors' => ['gallery']])->assertStatus(422);

        /* A value its field refuses: said by the field's label, the block as
           converted, and once it is selected, its settings with the message
           at the field and the value refused left out. */
        $wrong = [...$posted, 'data' => ['title' => 'Uno', 'weight' => 'heavy', 'shown' => '1']];
        $refused = $this->post($block, $wrong)->assertStatus(422);
        $this->assertSame(['The Weight field must be a number.'], $refused->json('messages'));
        $this->assertSame('heavy', $refused->json('block.data.weight'));
        $this->assertNull($refused->json('form'));
        $form = $this->post($block, ['block' => json_encode([...$slide, 'data' => [...$slide['data'], 'weight' => 'heavy']]), ...$inside])->assertStatus(422)->json('form');
        $this->assertMatchesRegularExpression('#name="data\[weight\]"(?:(?!value=)[^>])*>.*?The Weight field must be a number\.#s', $form);

        /* A refused save's messages, posted with it while it is unchanged,
           shown at their fields. */
        $this->assertStringContainsString('The Title field is required.', $this->post($block, ['block' => json_encode($slide), ...$inside, 'errors' => json_encode(['data.title' => ['The Title field is required.'], 'data.caption' => 'not a list'])])->assertOk()->json('form'));

        /* A relation in a block searches by the block's handle, among the
           blocks the type's content can hold. */
        $open = Mainstay::create(Story::class, ['title' => 'Open', 'slug' => 'open'], locale: 'en', overrideAccess: true);
        $spine = ['id' => 'p1', 'type' => 'spine', 'data' => ['story' => $open->id]];
        $this->assertStringContainsString('data-search="'.route('mainstay.relations', ['spread', 'spine.story', 'locale' => 'en']).'"', $this->post($block, ['block' => json_encode($spine), 'ancestors' => json_encode([['id' => 'h1', 'type' => 'shelf', 'data' => []]])])->assertOk()->json('form'));
        $this->get(route('mainstay.relations', ['spread', 'spine.story', 'q' => 'Op']))->assertOk()->assertSee('data-title="Open"', escape: false);
        $this->get(route('mainstay.relations', ['spread', 'spine.title', 'q' => 'Op']))->assertNotFound();
        $this->get(route('mainstay.relations', ['post', 'spine.story', 'q' => 'Op']))->assertNotFound();

        /* Refused: a block the content does not hold, as the document holds
           it or as the settings post it; and to whoever may not change it. */
        $this->post($block, ['block' => json_encode(['id' => 'x', 'type' => 'loud', 'data' => []])])->assertStatus(422)->assertExactJson(['messages' => ['The content holds no block of the type "loud" here.']]);
        $this->post($block, ['id' => 'x', 'type' => 'loud'])->assertStatus(422);
        $this->post(route('mainstay.block.create', 'leaflet'), ['block' => json_encode($gallery)])->assertNotFound();
        $this->signIn($this->user(['edit_spreads', 'edit_published_spreads']));
        $this->post($block, ['block' => json_encode($gallery)])->assertForbidden()->assertJsonPath('messages.0', 'This needs edit_others_spreads.');
        $this->assertSame(0, DB::table('mainstay_drafts')->count(), 'Nothing written.');
    }

    #[Test]
    public function a_save_refused_for_a_blocks_field_is_listed_above_the_page_naming_the_block_and_marks_it(): void
    {
        [$spread, $gallery, $slide, $block] = $this->galleried();
        $edit = route('mainstay.entries.edit', ['spread', $spread->id]);
        $slide['data']['weight'] = 'heavy';

        $this->post(route('mainstay.entries.update', ['spread', $spread->id]), [...$this->drawn($this->get($edit)), 'content' => json_encode(self::said('Text', self::holding($gallery, $slide)))])
            ->assertSessionHasErrors('content.content.1.content.0.attrs.data.weight');

        $page = $this->get($edit)->assertOk()->assertSee('<li data-invalid-block="s1">Slide: The Weight field must be a number.</li>', escape: false);
        preg_match('/data-invalid="([^"]*)"/', $page->getContent(), $invalid);
        $this->assertSame(['s1' => ['label' => 'Slide', 'messages' => ['data.weight' => ['The Weight field must be a number.']]]], json_decode(html_entity_decode($invalid[1]), true));
        $this->get($edit)->assertDontSee('data-canvas-invalid', escape: false);

        /* A publish refused for what only a publish asks: the draft is saved,
           the block marked from it, the page still drawn, and its settings
           told when the block is posted with its errors. */
        $slide['data'] = ['title' => '', 'weight' => 2, 'shown' => true];
        $this->post(route('mainstay.entries.update', ['spread', $spread->id]), [...$this->drawn($this->get($edit)), 'intent' => 'publish', 'content' => json_encode(self::said('Text', self::holding($gallery, $slide)))])
            ->assertSessionHasErrors('content.content.1.content.0.attrs.data.title');

        $page = $this->get($edit)->assertOk()->assertSee('<li data-invalid-block="s1">Slide: The Title field is required.</li>', escape: false);
        preg_match('/data-invalid="([^"]*)"/', $page->getContent(), $invalid);
        $messages = json_decode(html_entity_decode($invalid[1]), true)['s1']['messages'];
        $this->assertSame(['data.title' => ['The Title field is required.']], $messages);
        $this->post(route('mainstay.canvas.entry', ['spread', $spread->id]), $this->drawn($page))->assertOk();
        $form = $this->post($block, ['block' => json_encode($slide), 'ancestors' => json_encode([$gallery]), 'errors' => json_encode($messages)])->assertOk()->json('form');
        $this->assertStringContainsString('The Title field is required.', $form);
    }

    #[Test]
    public function a_slides_title_changed_in_its_settings_is_saved_with_the_pages_title_unchanged(): void
    {
        [$spread, $gallery, , $block] = $this->galleried();
        $edit = route('mainstay.entries.edit', ['spread', $spread->id]);
        $form = $this->get($edit);

        /* The settings' slide title is no input of the entry's form. */
        $this->assertStringNotContainsString('data[title]', Str::between($form->getContent(), 'id="entry-form"', '</form>'));

        $changed = $this->post($block, ['id' => 's1', 'type' => 'slide', 'data' => ['title' => 'Renamed', 'weight' => '2', 'shown' => '1'], 'ancestors' => json_encode([$gallery])])->assertOk();
        $this->post(route('mainstay.entries.update', ['spread', $spread->id]), [...$this->drawn($form), 'content' => json_encode(self::said('Text', self::holding($gallery, $changed->json('block'))))])->assertSessionHasNoErrors();

        $drafted = Mainstay::drafts()->of(Spread::class, $spread->id, locale: 'en', overrideAccess: true)->entry;
        $this->assertSame(['First', 'Renamed', 'Shots'], [$drafted->title, $drafted->content->blocks()[0]->inner()[0]->title, $drafted->content->blocks()[0]->caption]);
    }
}
