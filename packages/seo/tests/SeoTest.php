<?php

namespace Mainstay\Seo\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use Mainstay\Api\Schema;
use Mainstay\Api\TypeScript;
use Mainstay\Auth\Role;
use Mainstay\Auth\User;
use Mainstay\Content\Media;
use Mainstay\Facades\Mainstay;
use Mainstay\Mainstay as Registry;
use Mainstay\Seo\Head;
use Mainstay\Seo\LocaleSettings;
use Mainstay\Seo\PageText;
use Mainstay\Seo\Seo;
use Mainstay\Seo\SeoMeta;
use Mainstay\Seo\SeoPlugin;
use Mainstay\Seo\SeoTab;
use Mainstay\Seo\Sitemap;
use Mainstay\Seo\Tests\Fixtures\Banner;
use Mainstay\Seo\Tests\Fixtures\Late;
use Mainstay\Seo\Tests\Fixtures\Members;
use Mainstay\Seo\Tests\Fixtures\Page;
use Mainstay\Seo\Tests\Fixtures\Plain;
use Mainstay\Seo\Tests\Fixtures\Post;
use Mainstay\Seo\Tests\Fixtures\Probe;
use Mainstay\Seo\Tests\Fixtures\Unrouted;
use Mainstay\Seo\Tests\Fixtures\Variant;
use Mainstay\Site\Addresses;
use Mainstay\Site\SitePlugin;
use Mainstay\Tests\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Test;

/*
 | The SEO plugin as a site uses it: the field through the store, drafts and
 | locales; the head a layout draws; the sitemap and robots.txt a crawler
 | fetches; the tab and the settings screen an editor uses; and the page
 | the tab's checks read.
 */
class SeoTest extends DatabaseTestCase
{
    private int $made = 0;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mainstay.plugins', [SitePlugin::class, SeoPlugin::class]);
        $app['config']->set('mainstay.locales', ['en' => '/', 'nl' => '/nl']);
        $app['view']->addLocation(__DIR__.'/Fixtures/views');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        app(Registry::class)->types([Post::class, Page::class, Plain::class, Unrouted::class, Probe::class, Members::class, Banner::class]);
        $this->artisan('mainstay:sync')->assertSuccessful();
        Cache::flush();
    }

    private function entry(array $data = [], string $locale = 'en'): Post
    {
        return Mainstay::create(Post::class, ['title' => 'Hello', 'slug' => 'hello', ...$data], locale: $locale, overrideAccess: true);
    }

    private function signIn(array $capabilities = ['*']): User
    {
        $role = Role::query()->create(['name' => 'role-'.++$this->made, 'capabilities' => $capabilities]);
        $user = User::query()->create(['name' => 'Ada', 'email' => "ada-{$this->made}@example.com", 'password' => 'correct horse battery', 'role_id' => $role->id]);
        $this->flushSession();
        $this->actingAs($user, 'mainstay');

        return $user;
    }

    /* A different width makes different bytes: the library keeps one copy of an image. */
    private function png(int $width = 40): string
    {
        $image = imagecreatetruecolor($width, 20);
        $path = tempnam(sys_get_temp_dir(), 'seo').'.png';
        imagepng($image, $path);

        return $path;
    }

    private function drawn(Post|Page $entry): string
    {
        return Blade::render('<x-seo::head :entry="$entry" />', ['entry' => $entry]);
    }

    private function jsonLd(string $html): array
    {
        $this->assertSame(1, preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $found), 'One JSON-LD script.');

        return json_decode($found[1], true, flags: JSON_THROW_ON_ERROR);
    }

    /* ---- the field ---- */

    #[Test]
    public function the_field_is_kept_with_the_entry_through_drafts_publishing_and_locales(): void
    {
        $post = $this->entry(['seo' => ['title' => 'Custom', 'noindex' => true, 'keyphrase' => 'hello world']]);
        $read = Mainstay::findById(Post::class, $post->id, locale: 'en');

        $this->assertInstanceOf(SeoMeta::class, $read->seo);
        $this->assertSame('Custom', $read->seo->title);
        $this->assertTrue($read->seo->noindex);
        $this->assertFalse($read->seo->nofollow);
        $this->assertSame(['title' => 'Custom', 'noindex' => true, 'keyphrase' => 'hello world'], $read->seo->toArray());

        $draft = Mainstay::drafts()->save(Post::class, ['seo' => ['description' => 'In a draft']], entry: $post->id, locale: 'en', overrideAccess: true);
        $this->assertSame('Custom', Mainstay::findById(Post::class, $post->id, locale: 'en')->seo->title, 'A draft is not live.');
        $this->assertSame('In a draft', $draft->entry->seo->description);

        Mainstay::drafts()->publish($draft->id, overrideAccess: true);
        $live = Mainstay::findById(Post::class, $post->id, locale: 'en')->seo;
        $this->assertSame(['description' => 'In a draft'], $live->toArray(), 'What a publish writes replaces what was live.');

        Mainstay::update(Post::class, $post->id, ['title' => 'Hallo', 'slug' => 'hallo', 'seo' => ['title' => 'Nederlands']], locale: 'nl', overrideAccess: true);
        $this->assertSame('Nederlands', Mainstay::findById(Post::class, $post->id, locale: 'nl')->seo->title);
        $this->assertSame('In a draft', Mainstay::findById(Post::class, $post->id, locale: 'en')->seo->description, 'Translated: one value per language.');

        $revisions = Mainstay::revisions()->of(Post::class, $post->id, overrideAccess: true);
        $this->assertGreaterThan(0, $revisions->count(), 'A publish files a revision, SEO with it.');

        Mainstay::update(Post::class, $post->id, ['seo' => null], locale: 'en', overrideAccess: true);
        $this->assertNull(Mainstay::findById(Post::class, $post->id, locale: 'en')->seo);
        $this->assertNull(DB::table('post_locales')->where('locale', 'en')->value('seo'), 'Nothing set stores no JSON.');
    }

    #[Test]
    public function a_field_declared_not_localized_is_one_value_for_every_language(): void
    {
        $page = Mainstay::create(Page::class, ['title' => 'About', 'slug' => 'about', 'seo' => ['description' => 'Shared']], locale: 'en', overrideAccess: true);
        Mainstay::update(Page::class, $page->id, ['title' => 'Over', 'slug' => 'over'], locale: 'nl', overrideAccess: true);

        $this->assertSame('Shared', Mainstay::findById(Page::class, $page->id, locale: 'nl')->seo->description);
    }

    #[Test]
    public function a_form_blank_is_nothing_set_and_a_posted_array_is_cleaned(): void
    {
        $field = Mainstay::fields(Post::class)['seo'];

        $this->assertSame(['nofollow' => true], $field->fromForm(['title' => '  ', 'description' => '', 'noindex' => '0', 'nofollow' => '1', 'social_image' => '']));
        $this->assertTrue($field->fromForm(['nofollow' => '1'])['nofollow']);
        $this->assertNull($field->serialize($field->fromForm(['title' => '', 'noindex' => '0'])), 'An empty form stores null.');
        $this->assertNull($field->fromForm('not an array'));
    }

    #[Test]
    public function validation_refuses_unknown_keys_long_values_and_wrong_kinds(): void
    {
        $cases = [
            'unknown key' => [['robots' => 'all'], 'seo'],
            'title too long' => [['title' => str_repeat('a', 201)], 'seo.title'],
            'description too long' => [['description' => str_repeat('a', 501)], 'seo.description'],
            'canonical not a URL' => [['canonical' => 'javascript:alert(1)'], 'seo.canonical'],
            'noindex not a flag' => [['noindex' => 'maybe'], 'seo.noindex'],
            'image not in the library' => [['social_image' => 999], 'seo.social_image'],
        ];

        foreach ($cases as $label => [$seo, $key]) {
            try {
                $this->entry(['seo' => $seo, 'slug' => 'case-'.md5($label)]);
                $this->fail("{$label} was accepted.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($key, $exception->errors(), $label);
            }
        }
    }

    #[Test]
    public function the_social_image_is_a_media_id_read_back_as_a_media_with_its_alt(): void
    {
        $media = Mainstay::media()->upload($this->png(), alt: ['en' => 'A "square"', 'nl' => 'Een vierkant'], overrideAccess: true);
        $post = $this->entry(['seo' => ['social_image' => $media->id]]);

        $read = Mainstay::findById(Post::class, $post->id, locale: 'en')->seo->socialImage;
        $this->assertInstanceOf(Media::class, $read);
        $this->assertSame('A "square"', $read->alt);
        $this->assertNotNull($read->url('social'), 'The library\'s own 640 copy, since the original is never served.');
        $this->assertSame(['social_image' => $media->id], DB::table('post_locales')->get()->map(fn ($row) => json_decode($row->seo, true))->first());
    }

    #[Test]
    public function the_schema_and_typescript_describe_the_field(): void
    {
        $schema = Mainstay::schema(Post::class)['properties']['seo'];

        $this->assertSame(['object', 'null'], $schema['type']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertEqualsCanonicalizing(array_keys(SeoMeta::KEYS), array_keys($schema['properties']));
        $this->assertSame(200, $schema['properties']['title']['maxLength']);

        $document = (new Schema(app(Registry::class)))->document();
        $this->assertSame($schema, $document['$defs']['post']['properties']['seo']);
        $this->assertStringContainsString('seo: {', TypeScript::from($document));
    }

    #[Test]
    public function a_field_whose_property_cannot_be_null_or_is_not_a_seo_meta_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Seo)->bind(new \ReflectionProperty(Fixtures\Strict::class, 'seo'));
    }

    /* ---- the head ---- */

    #[Test]
    public function the_head_writes_the_title_in_the_template_and_falls_back_to_the_entry(): void
    {
        Mainstay::setSetting('seo.site_name', 'Acme');
        $post = $this->entry(['seo' => null]);
        $html = $this->drawn(Mainstay::findById(Post::class, $post->id));

        $this->assertStringContainsString('<title>Hello | Acme</title>', $html);
        $this->assertStringNotContainsString('<meta name="description"', $html, 'Nothing to say, nothing said.');
        $this->assertStringContainsString('<link rel="canonical" href="'.url('/blog/hello').'">', $html);
        $this->assertStringNotContainsString('name="robots"', $html);
        $this->assertStringContainsString('<meta property="og:title" content="Hello">', $html);
        $this->assertStringContainsString('<meta name="twitter:card" content="summary">', $html);

        Mainstay::setSetting('seo.default_description', 'The site says.');
        $this->assertStringContainsString('<meta name="description" content="The site says.">', $this->drawn(Mainstay::findById(Post::class, $post->id)));
    }

    #[Test]
    public function the_seo_values_win_and_each_locale_has_its_own_template(): void
    {
        Mainstay::setSetting('seo.site_name', 'Acme');
        app(LocaleSettings::class)->save('en', '%title% - %site%', null);
        app(LocaleSettings::class)->save('nl', '%site%: %title%', null);
        $post = $this->entry(['seo' => ['title' => 'Custom', 'description' => 'Own words', 'canonical' => 'https://example.org/original', 'nofollow' => true, 'social_title' => 'Share me', 'social_description' => 'Shared words']]);
        Mainstay::update(Post::class, $post->id, ['title' => 'Hallo', 'slug' => 'hallo'], locale: 'nl', overrideAccess: true);

        $html = $this->drawn(Mainstay::findById(Post::class, $post->id, locale: 'en'));
        $this->assertStringContainsString('<title>Custom - Acme</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="Own words">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://example.org/original">', $html);
        $this->assertStringContainsString('<meta name="robots" content="nofollow">', $html);
        $this->assertStringContainsString('<meta property="og:title" content="Share me">', $html);
        $this->assertStringContainsString('<meta name="twitter:description" content="Shared words">', $html);
        $this->assertStringContainsString('<meta property="og:type" content="article">', $html);

        $this->assertStringContainsString('<title>Acme: Hallo</title>', $this->drawn(Mainstay::findById(Post::class, $post->id, locale: 'nl')));
    }

    #[Test]
    public function an_empty_site_name_drops_its_token_and_the_separator_with_it(): void
    {
        $this->assertSame('Hello', app(Head::class)->title('%title% | %site%', 'Hello', ''));
        $this->assertSame('Hello', app(Head::class)->title('%site% - %title%', 'Hello', ''));
        $this->assertSame('Hello — Acme', app(Head::class)->title('%title% — %site%', 'Hello', 'Acme'));
    }

    #[Test]
    public function noindex_and_the_site_wide_setting_both_say_so(): void
    {
        $post = Mainstay::findById(Post::class, $this->entry(['seo' => ['noindex' => true]])->id);
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $this->drawn($post));

        Mainstay::setSetting('seo.noindex_site', true);
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $this->drawn(Mainstay::findById(Post::class, $post->id)));

        $plain = $this->entry(['slug' => 'indexable']);
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $this->drawn(Mainstay::findById(Post::class, $plain->id)), 'An entry with no noindex of its own is hidden too.');
    }

    #[Test]
    public function the_head_links_every_translation_as_hreflang(): void
    {
        $post = $this->entry();
        $this->assertStringNotContainsString('hreflang', $this->drawn(Mainstay::findById(Post::class, $post->id)), 'One language, no alternates.');

        Mainstay::update(Post::class, $post->id, ['title' => 'Hallo', 'slug' => 'hallo'], locale: 'nl', overrideAccess: true);
        $html = $this->drawn(Mainstay::findById(Post::class, $post->id, locale: 'en'));

        $this->assertStringContainsString('<link rel="alternate" hreflang="en" href="'.url('/blog/hello').'">', $html);
        $this->assertStringContainsString('<link rel="alternate" hreflang="nl" href="'.url('/nl/nieuws/hallo').'">', $html);
        $this->assertStringContainsString('<link rel="alternate" hreflang="x-default" href="'.url('/blog/hello').'">', $html);
    }

    #[Test]
    public function a_noindex_translation_is_not_offered_as_hreflang_by_the_head_either(): void
    {
        $post = $this->entry();
        Mainstay::update(Post::class, $post->id, ['title' => 'Verborgen', 'slug' => 'verborgen', 'seo' => ['noindex' => true]], locale: 'nl', overrideAccess: true);

        $this->assertStringNotContainsString('hreflang', $this->drawn(Mainstay::findById(Post::class, $post->id, locale: 'en')));

        Mainstay::update(Post::class, $post->id, ['seo' => null], locale: 'nl', overrideAccess: true);
        $this->assertStringContainsString('hreflang="nl"', $this->drawn(Mainstay::findById(Post::class, $post->id, locale: 'en')), 'Offered again once it is indexable.');
    }

    #[Test]
    public function the_social_image_is_an_absolute_address_of_the_media_route_on_the_current_host(): void
    {
        $image = Mainstay::media()->upload($this->png(), alt: ['en' => 'Own', 'nl' => 'Eigen'], overrideAccess: true);
        $html = $this->drawn($this->entry(['seo' => ['social_image' => $image->id]]));

        $this->assertMatchesRegularExpression('#<meta property="og:image" content="'.preg_quote(url('/media/'), '#').'[^"]+">#', $html);
    }

    #[Test]
    public function a_restricted_social_image_is_never_printed_and_the_locales_default_stands_in(): void
    {
        $default = Mainstay::media()->upload($this->png(), alt: ['en' => 'Default', 'nl' => 'Standaard'], overrideAccess: true);
        app(LocaleSettings::class)->save('en', null, $default->id);
        $restricted = Mainstay::media()->upload($this->png(50), alt: ['en' => 'Private', 'nl' => 'Prive'], overrideAccess: true, visibility: Media::RESTRICTED);

        $post = $this->entry(['seo' => ['social_image' => $restricted->id]]);
        $html = $this->drawn($post);

        $this->assertStringContainsString('<meta property="og:image:alt" content="Default">', $html);
        $this->assertStringNotContainsString('/media/s/', $html, 'No expiring link in a head.');

        app(LocaleSettings::class)->save('en', null, $restricted->id);
        $html = $this->drawn($post);

        $this->assertStringNotContainsString('og:image', $html);
        $this->assertStringContainsString('content="summary"', $html);
    }

    #[Test]
    public function a_trashed_social_image_is_passed_over_for_the_locales_default_and_for_nothing_when_that_is_trashed_too(): void
    {
        $default = Mainstay::media()->upload($this->png(), alt: ['en' => 'Default', 'nl' => 'Standaard'], overrideAccess: true);
        $own = Mainstay::media()->upload($this->png(50), alt: ['en' => 'Own', 'nl' => 'Eigen'], overrideAccess: true);
        app(LocaleSettings::class)->save('en', null, $default->id);
        $post = $this->entry(['seo' => ['social_image' => $own->id]]);

        Mainstay::media()->delete($own->id, overrideAccess: true);
        $html = $this->drawn(Mainstay::findById(Post::class, $post->id));

        $this->assertStringContainsString('<meta property="og:image:alt" content="Default">', $html);
        $this->assertStringNotContainsString('content="Own"', $html);

        Mainstay::media()->delete($default->id, overrideAccess: true);
        $html = $this->drawn(Mainstay::findById(Post::class, $post->id));

        $this->assertStringNotContainsString('og:image', $html);
        $this->assertStringNotContainsString('twitter:image', $html);
        $this->assertStringContainsString('content="summary"', $html);
    }

    #[Test]
    public function the_social_image_falls_back_to_the_locales_default(): void
    {
        $default = Mainstay::media()->upload($this->png(), alt: ['en' => 'Default', 'nl' => 'Standaard'], overrideAccess: true);
        app(LocaleSettings::class)->save('en', null, $default->id);
        $post = $this->entry();

        $html = $this->drawn(Mainstay::findById(Post::class, $post->id));
        $this->assertMatchesRegularExpression('#<meta property="og:image" content="http[^"]+/media/[^"]+">#', $html);
        $this->assertStringContainsString('<meta property="og:image:alt" content="Default">', $html);
        $this->assertStringContainsString('content="summary_large_image"', $html);
    }

    #[Test]
    public function json_ld_is_valid_json_with_an_organization_an_article_and_breadcrumbs(): void
    {
        Mainstay::setSetting('seo.site_name', 'Acme');
        Mainstay::setSetting('seo.organization_name', 'Acme Inc.');
        $post = $this->entry(['author' => 'Ada', 'publishedAt' => CarbonImmutable::parse('2026-03-04 10:00:00')]);
        $graph = $this->jsonLd($this->drawn(Mainstay::findById(Post::class, $post->id)))['@graph'];

        $this->assertSame(['Organization', 'Article'], array_column($graph, '@type'), 'No parent: no breadcrumbs.');
        $this->assertSame('Acme Inc.', $graph[0]['name']);
        $this->assertSame('Hello', $graph[1]['headline']);
        $this->assertSame('Ada', $graph[1]['author']['name']);
        $this->assertStringStartsWith('2026-03-04T10:00:00', $graph[1]['datePublished']);

        $parent = Mainstay::create(Page::class, ['title' => 'Docs', 'slug' => 'docs'], overrideAccess: true);
        $child = Mainstay::create(Page::class, ['title' => 'Install', 'slug' => 'install', 'parent' => $parent->id], overrideAccess: true);
        $crumbs = array_values(array_filter($this->jsonLd($this->drawn(Mainstay::findById(Page::class, $child->id)))['@graph'], fn (array $node) => $node['@type'] === 'BreadcrumbList'))[0];

        $this->assertSame(['Acme', 'Docs', 'Install'], array_column($crumbs['itemListElement'], 'name'));
        $this->assertSame([1, 2, 3], array_column($crumbs['itemListElement'], 'position'));
        $this->assertSame(url('/docs'), $crumbs['itemListElement'][1]['item']);
    }

    #[Test]
    public function titles_with_quotes_and_script_tags_cannot_break_out_of_the_head(): void
    {
        Mainstay::setSetting('seo.site_name', 'Acme "&" <b>');
        $evil = 'x"><script>alert(1)</script></script><!-- \'';
        $post = $this->entry(['title' => $evil, 'seo' => ['description' => $evil, 'social_title' => $evil]]);
        $html = $this->drawn(Mainstay::findById(Post::class, $post->id));

        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertSame(1, substr_count($html, '<script'), 'Only the JSON-LD script.');
        $this->assertSame(1, substr_count($html, '</script>'));
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $graph = $this->jsonLd($html)['@graph'];
        $this->assertSame($evil, $graph[1]['headline'], 'The JSON still decodes to what was written.');
        $this->assertStringNotContainsString('<', explode('</script>', explode('<script type="application/ld+json">', $html)[1])[0], 'The script holds no raw angle bracket to close itself with.');
    }

    #[Test]
    public function an_ampersand_is_escaped_inside_the_json(): void
    {
        $post = $this->entry(['title' => 'Fish & chips', 'author' => 'Tom & Jerry', 'publishedAt' => CarbonImmutable::parse('2026-01-01')]);
        $html = $this->drawn(Mainstay::findById(Post::class, $post->id));
        $script = explode('</script>', explode('<script type="application/ld+json">', $html)[1])[0];

        $this->assertStringNotContainsString('&', $script);
        $this->assertStringContainsString('Fish \u0026 chips', $script);
        $this->assertSame('Tom & Jerry', $this->jsonLd($html)['@graph'][0]['author']['name']);
    }

    #[Test]
    public function a_page_that_is_no_entry_passes_its_own_title_and_description(): void
    {
        $html = Blade::render('<x-seo::head title="Contact" description="Write to us" />');

        $this->assertStringContainsString('<title>Contact</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="Write to us">', $html);
        $this->assertStringContainsString('<link rel="canonical"', $html);
    }

    /* ---- the sitemap and robots.txt ---- */

    #[Test]
    public function the_sitemap_lists_live_entries_alone_and_leaves_out_noindex_ones(): void
    {
        $this->entry(['slug' => 'live', 'title' => 'Live']);
        $this->entry(['slug' => 'hidden', 'title' => 'Hidden', 'seo' => ['noindex' => true]]);
        Mainstay::drafts()->save(Post::class, ['title' => 'Draft', 'slug' => 'draft'], locale: 'en', overrideAccess: true);
        Mainstay::create(Plain::class, ['title' => 'Plain', 'slug' => 'p'], overrideAccess: true);
        Mainstay::create(Unrouted::class, ['title' => 'No address'], overrideAccess: true);

        $xml = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=utf-8')->getContent();
        $locs = $this->locs($xml);

        $this->assertEqualsCanonicalizing([url('/blog/live'), url('/plain/p')], $locs);
        $this->assertStringNotContainsString('hidden', $xml, 'noindex is dropped in PHP, since the store cannot filter JSON.');
        $this->assertStringNotContainsString('draft', $xml);
        $this->assertMatchesRegularExpression('#<lastmod>\d{4}-\d\d-\d\dT#', $xml);
    }

    /** @return list<string> */
    private function locs(string $xml): array
    {
        preg_match_all('#<loc>(.*?)</loc>#', $xml, $found);

        return array_map(html_entity_decode(...), $found[1]);
    }

    #[Test]
    public function the_sitemap_carries_hreflang_alternates_across_locales(): void
    {
        $post = $this->entry(['slug' => 'live']);
        Mainstay::update(Post::class, $post->id, ['title' => 'Levend', 'slug' => 'levend'], locale: 'nl', overrideAccess: true);

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertEqualsCanonicalizing([url('/blog/live'), url('/nl/nieuws/levend')], $this->locs($xml));
        $this->assertSame(2, substr_count($xml, 'hreflang="en"'), 'Every url lists every language, itself included.');
        $this->assertSame(2, substr_count($xml, 'hreflang="nl"'));
        $this->assertSame(2, substr_count($xml, 'hreflang="x-default" href="'.url('/blog/live').'"'));
        $this->assertStringContainsString('xmlns:xhtml="http://www.w3.org/1999/xhtml"', $xml);
        simplexml_load_string($xml) ?: $this->fail('The sitemap is not well-formed XML.');
    }

    #[Test]
    public function a_noindex_translation_is_neither_listed_nor_offered_as_an_alternate(): void
    {
        $post = $this->entry(['slug' => 'live']);
        Mainstay::update(Post::class, $post->id, ['title' => 'Verborgen', 'slug' => 'verborgen', 'seo' => ['noindex' => true]], locale: 'nl', overrideAccess: true);

        $xml = $this->get('/sitemap.xml')->getContent();

        $this->assertSame([url('/blog/live')], $this->locs($xml));
        $this->assertStringNotContainsString('hreflang', $xml, 'A lone language has no alternates.');
    }

    #[Test]
    public function the_sitemap_is_per_host_when_locales_have_hosts(): void
    {
        config(['mainstay.locales' => ['en' => 'https://example.com', 'nl' => 'https://example.nl']]);
        $post = $this->entry(['slug' => 'live']);
        Mainstay::update(Post::class, $post->id, ['title' => 'Levend', 'slug' => 'levend'], locale: 'nl', overrideAccess: true);

        $en = $this->call('GET', 'http://example.com/sitemap.xml')->assertOk()->getContent();
        $nl = $this->call('GET', 'http://example.nl/sitemap.xml')->assertOk()->getContent();

        $this->assertSame(['https://example.com/blog/live'], $this->locs($en));
        $this->assertSame(['https://example.nl/nieuws/levend'], $this->locs($nl));
        $this->assertStringContainsString('hreflang="nl" href="https://example.nl/nieuws/levend"', $en, 'Alternates may point to the other host.');
    }

    #[Test]
    public function the_sitemap_is_kept_until_content_changes(): void
    {
        $post = $this->entry(['slug' => 'one']);
        $this->assertSame([url('/blog/one')], $this->locs($this->get('/sitemap.xml')->getContent()));

        DB::table('uris')->where('entry_id', $post->id)->update(['uri' => '/blog/sneaky']);
        $this->assertSame([url('/blog/one')], $this->locs($this->get('/sitemap.xml')->getContent()), 'Served from the cache.');

        $two = $this->entry(['slug' => 'two']);
        $this->assertEqualsCanonicalizing([url('/blog/sneaky'), url('/blog/two')], $this->locs($this->get('/sitemap.xml')->getContent()), 'A save busts it.');

        $draft = Mainstay::drafts()->save(Post::class, ['seo' => ['noindex' => true]], entry: $two->id, locale: 'en', overrideAccess: true);
        $this->assertCount(2, $this->locs($this->get('/sitemap.xml')->getContent()), 'A draft is not live: nothing changed.');
        Mainstay::drafts()->publish($draft->id, overrideAccess: true);
        $this->assertSame([url('/blog/sneaky')], $this->locs($this->get('/sitemap.xml')->getContent()), 'A publish busts it.');

        Mainstay::delete(Post::class, $post->id, overrideAccess: true);
        $this->assertSame([], $this->locs($this->get('/sitemap.xml')->getContent()), 'Trashing busts it.');

        Mainstay::restore(Post::class, $post->id, overrideAccess: true);
        $this->assertCount(1, $this->locs($this->get('/sitemap.xml')->getContent()), 'Restoring busts it.');
    }

    /** @return int the queries the callback ran */
    private function queries(callable $run): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    #[Test]
    public function a_warm_sitemap_and_head_cost_the_same_queries_however_many_pages_there_are(): void
    {
        foreach (range(1, 5) as $n) {
            $this->entry(['slug' => "few-{$n}"]);
        }
        $first = $this->entry(['slug' => 'first']);
        Mainstay::update(Post::class, $first->id, ['title' => 'Eerste', 'slug' => 'eerste'], locale: 'nl', overrideAccess: true);

        $this->get('/sitemap.xml');
        $sitemapFew = $this->queries(fn () => $this->get('/sitemap.xml')->assertOk());
        $entry = Mainstay::findById(Post::class, $first->id);
        $headFew = $this->queries(fn () => $this->drawn($entry));

        foreach (range(1, 45) as $n) {
            $this->entry(['slug' => "many-{$n}"]);
        }

        $this->get('/sitemap.xml');
        $this->assertCount(52, $this->locs($this->get('/sitemap.xml')->getContent()), 'Fifty-one posts, one in two languages.');
        $this->assertSame($sitemapFew, $this->queries(fn () => $this->get('/sitemap.xml')), 'The queries do not grow with the pages.');
        $this->assertSame($headFew, $this->queries(fn () => $this->drawn($entry)));
        $this->assertLessThanOrEqual(5, $headFew, 'The settings, the locales and the sitemap\'s token.');
    }

    #[Test]
    public function the_kept_sitemap_is_read_again_when_the_locales_or_the_routed_types_change_without_a_content_event(): void
    {
        $post = $this->entry(['slug' => 'live']);
        Mainstay::update(Post::class, $post->id, ['title' => 'Levend', 'slug' => 'levend'], locale: 'nl', overrideAccess: true);
        $this->assertCount(2, $this->locs($this->get('/sitemap.xml')->getContent()));
        $token = Cache::get('mainstay.seo.sitemap')['token'];

        config(['mainstay.locales' => ['en' => '/']]);
        $this->assertSame([url('/blog/live')], $this->locs($this->get('/sitemap.xml')->getContent()), 'A locale taken out of the config leaves the list at once.');
        config(['mainstay.locales' => ['en' => '/', 'nl' => '/nl']]);
        $this->assertCount(2, $this->locs($this->get('/sitemap.xml')->getContent()));

        app(Registry::class)->types([Late::class]);
        $this->artisan('mainstay:sync')->assertSuccessful();
        $this->get('/sitemap.xml');
        $this->assertNotSame($token, Cache::get('mainstay.seo.sitemap')['token'], 'A newly routed type, as a deploy brings one, is read for.');
    }

    #[Test]
    public function a_changed_route_pattern_alone_reads_the_kept_sitemap_again(): void
    {
        app(Registry::class)->types([Late::class]);
        $this->artisan('mainstay:sync')->assertSuccessful();
        Mainstay::create(Late::class, ['title' => 'Later', 'slug' => 'later'], overrideAccess: true);

        $this->assertSame([url('/late/later')], $this->locs($this->get('/sitemap.xml')->getContent()));
        $token = Cache::get('mainstay.seo.sitemap')['token'];

        /* The same handle, so the same table and the same rows, as a deploy that only edits #[Route] would leave. */
        $deployed = new Registry;
        $deployed->types([Post::class, Page::class, Plain::class, Unrouted::class, Probe::class, Members::class, Banner::class, Variant\Late::class]);
        $this->app->instance(Registry::class, $deployed);
        $this->assertSame('late', Variant\Late::handle());

        app(Sitemap::class)->pages('localhost');

        $this->assertNotSame($token, Cache::get('mainstay.seo.sitemap')['token'], 'The pattern is in the token, not only the handle.');
    }

    #[Test]
    public function the_sitemap_is_what_a_visitor_may_read_whoever_asks(): void
    {
        $this->entry(['slug' => 'live']);
        $this->signIn(['*']);

        $this->assertSame([url('/blog/live')], $this->locs($this->get('/sitemap.xml')->getContent()));
    }

    #[Test]
    public function robots_txt_points_at_the_hosts_sitemap_and_honours_the_site_wide_noindex(): void
    {
        $robots = $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=utf-8')->getContent();

        $this->assertStringContainsString("User-agent: *\n", $robots);
        $this->assertStringContainsString('Disallow: /admin/', $robots);
        $this->assertStringContainsString('Sitemap: '.url('/sitemap.xml'), $robots);

        Mainstay::setSetting('seo.noindex_site', true);
        $robots = $this->get('/robots.txt')->getContent();

        $this->assertStringContainsString("Disallow: /\n", $robots);
        $this->assertStringNotContainsString('Sitemap:', $robots);
    }

    #[Test]
    public function the_explicit_routes_beat_the_site_catch_all_and_an_entry_cannot_take_their_paths(): void
    {
        $names = collect(app('router')->getRoutes()->getRoutes())->map->getName()->filter()->all();
        $this->assertContains('mainstay.seo.sitemap', $names);
        $this->assertContains('mainstay.seo.robots', $names);

        $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=utf-8');
        $this->get('/not-an-entry')->assertNotFound();

        $addresses = app(Addresses::class);
        $this->assertSame('the route mainstay.seo.sitemap', $addresses->shadow('en', '/sitemap.xml'));
        $this->assertSame('the route mainstay.seo.robots', $addresses->shadow('en', '/robots.txt'));
        $this->assertNull($addresses->shadow('en', '/sitemaps'));
    }

    #[Test]
    public function the_sitemap_follows_the_front_page_which_core_announces_no_change_of(): void
    {
        $one = $this->entry(['slug' => 'one']);
        $this->entry(['slug' => 'two']);
        $this->assertEqualsCanonicalizing([url('/blog/one'), url('/blog/two')], $this->locs($this->get('/sitemap.xml')->getContent()));

        Mainstay::setFrontPage(Post::class, $one->id, overrideAccess: true);

        $locs = $this->locs($this->get('/sitemap.xml')->getContent());
        $this->assertEqualsCanonicalizing([url('/'), url('/blog/two')], $locs, 'The front page is listed at its root, and its old path is gone.');

        Mainstay::setFrontPage(null, overrideAccess: true);
        $this->assertEqualsCanonicalizing([url('/blog/one'), url('/blog/two')], $this->locs($this->get('/sitemap.xml')->getContent()), 'And back again.');
    }

    #[Test]
    public function a_site_hidden_from_search_engines_has_an_empty_sitemap(): void
    {
        $this->entry(['slug' => 'live']);
        $this->assertCount(1, $this->locs($this->get('/sitemap.xml')->getContent()));

        Mainstay::setSetting('seo.noindex_site', true);
        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertSame([], $this->locs($xml));
        $this->assertNotFalse(simplexml_load_string($xml), 'Still a well-formed urlset.');

        Mainstay::setSetting('seo.noindex_site', false);
        $this->assertCount(1, $this->locs($this->get('/sitemap.xml')->getContent()), 'Shown again at once, with no content change.');
    }

    #[Test]
    public function the_sitemap_leaves_out_what_a_visitor_may_not_read_even_for_a_signed_in_asker(): void
    {
        Mainstay::create(Members::class, ['title' => 'Members only', 'slug' => 'inside'], overrideAccess: true);
        $this->entry(['slug' => 'open']);

        $this->signIn();
        $this->assertSame([url('/blog/open')], $this->locs($this->get('/sitemap.xml')->getContent()));
        $this->assertStringNotContainsString('inside', $this->get('/sitemap.xml')->getContent());
    }

    /* ---- boot ---- */

    #[Test]
    public function booting_without_the_site_plugin_says_what_is_missing(): void
    {
        config(['mainstay.plugins' => [SeoPlugin::class]]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(SitePlugin::class);

        (new SeoPlugin)->boot($this->app);
    }

    #[Test]
    public function the_plugin_declares_its_label_its_handle_and_its_settings(): void
    {
        $this->assertSame('seo', SeoPlugin::handle());
        $this->assertSame('SEO', SeoPlugin::label());
        $this->assertSame(['site_name', 'default_description', 'organization_name', 'noindex_site'], array_map(fn ($setting) => $setting->key, (new SeoPlugin)->settings()));
        $this->assertArrayHasKey('seo', Mainstay::plugins());
    }

    /* ---- settings ---- */

    #[Test]
    public function the_settings_screens_are_for_whoever_manages_settings(): void
    {
        $this->signIn(['view_post']);
        $this->get(route('mainstay.settings.seo.index'))->assertNotFound();
        $this->post(route('mainstay.settings.seo.update'), ['locales' => ['en' => ['title_template' => '%title%']]])->assertNotFound();
        $this->assertSame(LocaleSettings::DEFAULT_TEMPLATE, app(LocaleSettings::class)->template('en'));

        $this->signIn(['manage_settings']);
        $this->get(route('mainstay.settings.seo.index'))->assertOk()->assertSee('Title template')->assertSee('Default social image');
        $this->get(route('mainstay.settings'))->assertOk()->assertSee('Hide the whole site from search engines')->assertSee('href="/admin/settings/seo"', escape: false);
    }

    #[Test]
    public function the_per_locale_values_are_saved_validated_and_read_back(): void
    {
        $this->signIn(['manage_settings']);
        $media = Mainstay::media()->upload($this->png(), alt: ['en' => 'Logo', 'nl' => 'Logo'], overrideAccess: true);

        $this->post(route('mainstay.settings.seo.update'), ['locales' => ['en' => ['title_template' => '%title% / %site%', 'default_image' => (string) $media->id], 'nl' => ['title_template' => '', 'default_image' => '']]])
            ->assertRedirect(route('mainstay.settings.seo.index'));

        $settings = app(LocaleSettings::class);
        $this->assertSame('%title% / %site%', $settings->template('en'));
        $this->assertSame($media->id, $settings->imageId('en'));
        $this->assertSame(LocaleSettings::DEFAULT_TEMPLATE, $settings->template('nl'));
        $this->assertNull($settings->imageId('nl'));

        $this->get(route('mainstay.settings.seo.index'))->assertSee('value="%title% / %site%"', escape: false);

        $this->post(route('mainstay.settings.seo.update'), ['locales' => ['en' => ['title_template' => '%title%'.str_repeat('a', 113)]]])->assertSessionHasNoErrors();
        $this->post(route('mainstay.settings.seo.update'), ['locales' => ['en' => ['title_template' => '%title%'.str_repeat('a', 114)]]])->assertSessionHasErrors('locales.en.title_template');
        $this->post(route('mainstay.settings.seo.update'), ['locales' => ['en' => ['title_template' => '%title% / %site%', 'default_image' => (string) $media->id]]]);

        $this->post(route('mainstay.settings.seo.update'), ['locales' => ['en' => ['title_template' => 'No title here', 'default_image' => '999'], 'nl' => ['title_template' => str_repeat('a', 121)]]])
            ->assertSessionHasErrors(['locales.en.title_template', 'locales.en.default_image', 'locales.nl.title_template']);
        $this->assertSame('%title% / %site%', app(LocaleSettings::class)->template('en'), 'A refused save changes nothing.');
    }

    #[Test]
    public function the_default_social_image_refuses_a_document(): void
    {
        $this->signIn(['manage_settings']);
        $path = sys_get_temp_dir().'/seo-'.uniqid().'.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
        $document = Mainstay::media()->upload($path, title: ['en' => 'Report'], overrideAccess: true);
        unlink($path);

        $this->post(route('mainstay.settings.seo.update'), ['locales' => ['en' => ['title_template' => '%title%', 'default_image' => (string) $document->id]]])
            ->assertSessionHasErrors(['locales.en.default_image' => 'That image is not in the media library.']);
        $this->assertNull(app(LocaleSettings::class)->imageId('en'));
    }

    #[Test]
    public function the_general_settings_are_written_through_the_settings_screen(): void
    {
        $this->signIn(['manage_settings']);

        $this->post(route('mainstay.settings.update'), ['settings' => ['seo' => ['site_name' => 'Acme', 'default_description' => 'D', 'organization_name' => '', 'noindex_site' => '1']]])->assertRedirect();

        $this->assertSame('Acme', Mainstay::setting('seo.site_name'));
        $this->assertTrue(Mainstay::setting('seo.noindex_site'));
    }

    /* ---- the tab ---- */

    #[Test]
    public function the_tab_applies_to_types_that_declare_the_field_alone(): void
    {
        $tab = app(SeoTab::class);

        $this->assertTrue($tab->applies(Post::class));
        $this->assertTrue($tab->applies(Unrouted::class));
        $this->assertFalse($tab->applies(Plain::class));
        $this->assertFalse($tab->applies(Banner::class), 'A global has no page to draw, so it has no tab.');
        $this->assertSame(['seo'], array_map(fn ($each) => $each->handle(), Mainstay::entryTabs(Post::class)));
        $this->assertSame([], Mainstay::entryTabs(Plain::class));
    }

    #[Test]
    public function the_entry_form_draws_the_tab_with_its_inputs_and_details_points_to_it(): void
    {
        $this->signIn();
        $post = $this->entry(['seo' => ['title' => 'Custom', 'keyphrase' => 'hello', 'noindex' => true]]);

        $html = $this->get(route('mainstay.entries.edit', ['post', $post->id]))->assertOk()
            ->assertSee('id="seo-tab"', escape: false)
            ->assertSee('name="seo[title]"', escape: false)
            ->assertSee('name="seo[keyphrase]"', escape: false)
            ->assertSee('Search and social settings are edited on the SEO tab.')
            ->assertSee('vendor/seo/seo.js?v=', escape: false)
            ->getContent();

        $this->assertStringContainsString('value="Custom"', $html);
        $this->assertSame(1, substr_count($html, 'name="seo[title]"'), 'The inputs are in the tab alone.');
        $this->assertMatchesRegularExpression('#data-endpoint="[^"]+/post/'.$post->id.'/seo-preview"#', $html);
        $this->assertMatchesRegularExpression('#name="seo\[noindex\]" value="1"[^>]*checked#', $html);

        $plain = Mainstay::create(Plain::class, ['title' => 'Plain', 'slug' => 'p'], overrideAccess: true);
        $this->get(route('mainstay.entries.edit', ['plain', $plain->id]))->assertOk()->assertDontSee('data-seo', escape: false)->assertDontSee('id="seo-tab"', escape: false);
    }

    #[Test]
    public function a_refused_seo_value_is_shown_on_the_tab_and_in_details(): void
    {
        $this->signIn();
        $post = $this->entry();

        $html = $this->followingRedirects()->from(route('mainstay.entries.edit', ['post', $post->id]))
            ->post(route('mainstay.entries.update', ['post', $post->id]), ['title' => 'Hello', 'slug' => 'hello', 'seo' => ['title' => str_repeat('a', 201), 'canonical' => 'nope', 'noindex' => '0']])
            ->assertOk()->getContent();

        $this->assertStringContainsString('The seo.title field must not be greater than 200 characters.', $html);
        $this->assertStringContainsString('The seo.canonical field must be a valid URL.', $html);
        $this->assertStringContainsString('data-seo-details-errors', $html);
        $this->assertSame(1, preg_match('#id="seo-title-error".*?greater than 200#s', $html), 'Beside the input in the tab.');
        $this->assertSame(1, preg_match('#id="seo-canonical-error".*?valid URL#s', $html));
        $this->assertStringContainsString('value="nope"', $html, 'What was typed is kept.');
    }

    #[Test]
    public function saving_the_form_writes_the_field_and_an_empty_form_writes_nothing(): void
    {
        $this->signIn();
        $post = $this->entry();

        $this->post(route('mainstay.entries.update', ['post', $post->id]), ['title' => 'Hello', 'slug' => 'hello', 'intent' => 'publish', 'seo' => ['title' => 'Typed', 'description' => '', 'noindex' => '1', 'nofollow' => '0', 'keyphrase' => 'hello']])->assertRedirect();
        $this->assertSame(['title' => 'Typed', 'noindex' => true, 'keyphrase' => 'hello'], Mainstay::findById(Post::class, $post->id)->seo->toArray());

        $this->post(route('mainstay.entries.update', ['post', $post->id]), ['title' => 'Hello', 'slug' => 'hello', 'intent' => 'publish', 'seo' => ['title' => '', 'noindex' => '0', 'nofollow' => '0']])->assertRedirect();
        $this->assertNull(Mainstay::findById(Post::class, $post->id)->seo);
    }

    /* ---- the preview the checks read ---- */

    #[Test]
    public function the_preview_answers_the_page_as_a_visitor_reads_it_without_writing_anything(): void
    {
        $this->signIn();
        $post = $this->entry(['title' => 'Stored title']);
        $before = [DB::table('post')->count(), DB::table('mainstay_drafts')->count(), DB::table('post_locales')->get()->toJson()];

        $answer = $this->postJson(route('mainstay.seo.preview.entry', ['post', $post->id]), ['title' => 'Typed, unsaved'])->assertOk()->json();

        $this->assertTrue($answer['rendered']);
        $this->assertSame([['level' => 1, 'text' => 'Typed, unsaved'], ['level' => 2, 'text' => 'Second']], $answer['headings'], 'The form\'s unsaved title, and the chrome\'s h1 left out.');
        $this->assertSame(['heading', 'paragraph', 'heading', 'paragraph'], $answer['order']);
        $this->assertSame(['First paragraph about Typed, unsaved.', 'More text with an internal link and an outbound one.'], $answer['paragraphs']);
        $this->assertSame([['href' => '/blog/other', 'text' => 'an internal link'], ['href' => 'https://example.org/x', 'text' => 'an outbound one']], $answer['links']);
        $this->assertSame([['alt' => 'A picture'], ['alt' => null]], $answer['images']);
        $this->assertSame($before, [DB::table('post')->count(), DB::table('mainstay_drafts')->count(), DB::table('post_locales')->get()->toJson()]);
    }

    #[Test]
    public function the_preview_reads_a_new_entry_and_a_draft(): void
    {
        $this->signIn();

        $this->postJson(route('mainstay.seo.preview.create', 'post'), ['title' => 'Brand new', 'slug' => 'brand-new'])->assertOk()->assertJsonPath('headings.0.text', 'Brand new');

        $draft = Mainstay::drafts()->save(Post::class, ['title' => 'In a draft', 'slug' => 'in-a-draft'], locale: 'en', overrideAccess: true);
        $this->postJson(route('mainstay.seo.preview.draft', ['post', $draft->id]), [])->assertOk()->assertJsonPath('headings.0.text', 'In a draft');
    }

    #[Test]
    public function the_preview_asks_who_may_edit_and_which_type_it_is(): void
    {
        $post = $this->entry();

        $this->postJson(route('mainstay.seo.preview.entry', ['post', $post->id]), [])->assertUnauthorized();

        $this->signIn(['view_post']);
        $this->postJson(route('mainstay.seo.preview.entry', ['post', $post->id]), ['title' => 'Nope'])->assertForbidden();

        $this->signIn();
        $plain = Mainstay::create(Plain::class, ['title' => 'Plain', 'slug' => 'p'], overrideAccess: true);
        $this->postJson(route('mainstay.seo.preview.entry', ['plain', $plain->id]), [])->assertNotFound();
        $this->postJson(route('mainstay.seo.preview.entry', ['nothing', 1]), [])->assertNotFound();
    }

    #[Test]
    public function the_preview_refuses_a_change_as_a_save_would_and_has_no_page_for_a_type_without_one(): void
    {
        $this->signIn();
        $post = $this->entry();

        $this->postJson(route('mainstay.seo.preview.entry', ['post', $post->id]), ['seo' => ['title' => str_repeat('a', 201)]])
            ->assertStatus(422)->assertJsonPath('rendered', false);

        $unrouted = Mainstay::create(Unrouted::class, ['title' => 'No address'], overrideAccess: true);
        $this->postJson(route('mainstay.seo.preview.entry', ['unrouted', $unrouted->id]), [])->assertOk()->assertJsonPath('rendered', false);
    }

    #[Test]
    public function the_preview_draws_the_page_as_a_visitor_and_not_as_the_editor(): void
    {
        $this->signIn();
        $probe = Mainstay::create(Probe::class, ['title' => 'Looked at', 'slug' => 'looked'], overrideAccess: true);

        $answer = $this->postJson(route('mainstay.seo.preview.entry', ['probe', $probe->id]), [])->assertOk()->json();

        $this->assertSame(['visitor'], $answer['paragraphs'], 'The page does not see the account that asked.');
        $this->assertTrue(auth('mainstay')->check(), 'The editor is still signed in afterwards.');
    }

    #[Test]
    public function the_preview_says_nothing_of_an_error_but_that_there_was_one(): void
    {
        $this->signIn();
        $probe = Mainstay::create(Probe::class, ['title' => 'Looked at', 'slug' => 'looked'], overrideAccess: true);
        Exceptions::fake();

        $answer = $this->postJson(route('mainstay.seo.preview.entry', ['probe', $probe->id]), ['boom' => 1])->assertStatus(500);

        $this->assertFalse($answer->json('rendered'));
        $this->assertStringNotContainsString('secret', $answer->getContent());
        $this->assertStringNotContainsString('Mainstay\\\\Seo', $answer->getContent());
        $reported = Exceptions::reported();
        $this->assertCount(1, $reported, 'The error is reported, so the log has it.');
        $this->assertStringContainsString('secret', $reported[0]->getMessage());

        $missing = $this->postJson(route('mainstay.seo.preview.draft', ['probe', 9999]), [])->assertNotFound();
        $this->assertStringNotContainsString('Mainstay', $missing->getContent());
    }

    #[Test]
    public function the_page_text_is_the_main_content_without_the_chrome(): void
    {
        $text = (new PageText)->from('<body><nav><p>Menu</p></nav><div><p>Outside main</p></div><main><h2>Head</h2><p>Hello <b>world</b></p><p>  </p><aside><p>Aside</p></aside><a href="/x">Go</a></main></body>');

        $this->assertSame([['level' => 2, 'text' => 'Head']], $text['headings']);
        $this->assertSame(['Hello world'], $text['paragraphs']);
        $this->assertSame([['href' => '/x', 'text' => 'Go']], $text['links']);

        $without = (new PageText)->from('<html><body><article><p>In an article</p></article><p>Beside</p></body></html>');
        $this->assertSame(['In an article'], $without['paragraphs']);

        $body = (new PageText)->from('<p>Fragment <i>only</i></p>');
        $this->assertSame(['Fragment only'], $body['paragraphs']);
        $this->assertSame(['headings' => [], 'paragraphs' => [], 'order' => [], 'links' => [], 'images' => []], (new PageText)->from(''));
        $this->assertSame(['Café ünï'], (new PageText)->from('<main><p>Café ünï</p></main>')['paragraphs'], 'UTF-8 survives.');
    }
}
