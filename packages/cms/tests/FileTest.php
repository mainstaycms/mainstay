<?php

namespace Mainstay\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Mainstay\Auth\Role;
use Mainstay\Auth\User;
use Mainstay\Content\Media;
use Mainstay\Facades\Mainstay;
use Mainstay\Fields\File;
use Mainstay\Media\Documents;
use Mainstay\Site\Addresses;
use Mainstay\Tests\Fixtures\Brochured;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/*
 | Documents in the library and every file served by route: what is let in
 | by name and by bytes, what a File field takes and hands out, who is served
 | a public and a restricted file and with which headers, and the move of an
 | install's old public files onto the private disk.
 */
class FileTest extends DatabaseTestCase
{
    private const TITLE = ['en' => 'Annual report', 'nl' => 'Jaarverslag'];

    /** @var list<string> */
    private array $files = [];

    private int $made = 0;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mainstay.locales', ['en' => '/', 'nl' => '/nl']);
        $app['config']->set('mainstay.media.disk', 'media');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
        Storage::fake('local');
        Storage::fake('public');

        $this->declare(Brochured::class);
        $this->artisan('mainstay:sync')->assertSuccessful();
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), array_filter($this->files, is_file(...)));

        parent::tearDown();
    }

    private function file(string $name, string $bytes): string
    {
        $dir = sys_get_temp_dir().'/mainstay-file-'.uniqid();
        mkdir($dir);
        $this->files[] = $path = "{$dir}/{$name}";
        file_put_contents($path, $bytes);

        return $path;
    }

    private function pdf(string $text = 'one'): string
    {
        return $this->file('Report.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n% {$text}\ntrailer<</Root 1 0 R>>\n%%EOF\n");
    }

    /* A zip holding `$entries`, stored: enough of the format for libmagic
       and for the directory scan, which is all the library reads. */
    private function zip(string $name, array $entries): string
    {
        [$body, $directory, $count] = ['', '', 0];

        foreach ($entries as $entry => $content) {
            $crc = crc32($content);
            $header = pack('VvvvvvVVVvv', 0x04034B50, 20, 0, 0, 0, 0x21, $crc, strlen($content), strlen($content), strlen($entry), 0).$entry;
            $directory .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, 0, 0, 0, 0x21, $crc, strlen($content), strlen($content), strlen($entry), 0, 0, 0, 0, 0, strlen($body)).$entry;
            $body .= $header.$content;
            $count++;
        }

        return $this->file($name, $body.$directory.pack('VvvvvVVv', 0x06054B50, 0, 0, $count, $count, strlen($directory), strlen($body), 0));
    }

    private function docx(string $name = 'Letter.docx'): string
    {
        return $this->zip($name, ['[Content_Types].xml' => '<Types/>', '_rels/.rels' => '<Relationships/>', 'word/document.xml' => '<w:document/>']);
    }

    private function upload(string $path, array $extra = []): Media
    {
        return Mainstay::media()->upload($path, ...['title' => self::TITLE, 'overrideAccess' => true, ...$extra]);
    }

    private function refusal(callable $write): array
    {
        try {
            $write();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        $this->fail('The write was not refused.');
    }

    private function editor(): User
    {
        $role = Role::query()->where('name', 'administrator')->sole();

        return User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'correct horse battery', 'role_id' => $role->id]);
    }

    /* A request as a browser makes it: only the cookies it holds. */
    private function browser(array $cookies = []): static
    {
        $this->app['auth']->forgetGuards();
        $this->app['session.store']->flush();
        $this->app['cookie']->flushQueuedCookies();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];

        return $this->withUnencryptedCookies($cookies);
    }

    /* The cookies of an editor signed in through the login form. */
    private function signedIn(): array
    {
        $this->editor();
        $response = $this->browser()->post(route('mainstay.login'), ['email' => 'ada@example.com', 'password' => 'correct horse battery']);
        $jar = [];

        foreach ($response->headers->getCookies() as $cookie) {
            $jar[$cookie->getName()] = $cookie->getValue();
        }

        $this->assertArrayHasKey('mainstay_session', $jar);

        return $jar;
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(80, 40);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 0, 0));
        $this->files[] = $path = tempnam(sys_get_temp_dir(), 'mainstay-file-');
        imagepng($image, $path);

        return $path;
    }

    #[Test]
    public function documents_are_taken_by_name_and_bytes_and_kept_whole_on_the_media_disk(): void
    {
        $pdf = $this->upload($this->pdf());
        $this->assertSame(['application/pdf', 'Report.pdf'], [$pdf->type, $pdf->name]);
        $this->assertSame('Annual report', $pdf->title);
        $this->assertTrue($pdf->isDocument());
        $this->assertSame("%PDF-1.4\n", substr(Storage::disk('media')->get("media/{$pdf->hash}.pdf"), 0, 9));
        $this->assertSame([], Storage::disk('local')->allFiles(), 'No original of an image, and no copy of a document.');
        $this->assertSame(['pdf' => 1], array_count_values(array_map(fn (string $path) => pathinfo($path, PATHINFO_EXTENSION), Storage::disk('media')->allFiles())));

        $docx = $this->upload($this->docx());
        $xlsx = $this->upload($this->zip('Sheet.xlsx', ['[Content_Types].xml' => '<Types/>', 'xl/workbook.xml' => '<w/>']));
        $zip = $this->upload($this->zip('Bundle.zip', ['a.txt' => 'a', 'b.txt' => 'b']));
        $this->assertSame(['docx', 'xlsx', 'zip'], array_map(fn (Media $media) => Documents::extension($media->type), [$docx, $xlsx, $zip]));
        $this->assertSame(['public', 4], [$docx->visibility, DB::table('mainstay_media')->count()]);
        $this->assertSame(['file' => ["This file is already in the library, as file {$pdf->id}."]], $this->refusal(fn () => $this->upload($this->pdf())));
    }

    #[Test]
    public function what_is_not_an_allowed_document_by_name_and_by_bytes_is_refused_with_nothing_written(): void
    {
        $html = '<!doctype html><html><script>alert(1)</script></html>';
        $cases = [
            'html' => $this->file('page.html', $html),
            'html as pdf' => $this->file('page.pdf', $html),
            'svg' => $this->file('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>1</script></svg>'),
            'exe' => $this->file('setup.exe', "MZ\x90\x00\x03\x00\x00\x00 This program cannot be run in DOS mode."),
            'php' => $this->file('shell.php', '<?php system($_GET["c"]);'),
            'double extension' => $this->file('shell.php.pdf', '<?php system($_GET["c"]);'),
            'pdf bytes named docx' => $this->file('letter.docx', "%PDF-1.4\n%%EOF\n"),
            'plain zip named docx' => $this->zip('letter.docx', ['a.txt' => 'a']),
            'zip with the wrong folder named xlsx' => $this->zip('sheet.xlsx', ['[Content_Types].xml' => '<Types/>', 'word/document.xml' => '<w/>']),
            'js' => $this->file('app.js', 'alert(1)'),
            'text with php in it' => $this->file('notes.txt', '<?php echo 1;'),
        ];

        foreach ($cases as $label => $path) {
            $this->assertArrayHasKey('file', $this->refusal(fn () => $this->upload($path)), $label);
        }

        $this->assertSame(0, DB::table('mainstay_media')->count());
        $this->assertSame([], Storage::disk('media')->allFiles());

        config(['mainstay.media.max_document_kb' => 1]);
        $this->assertSame(['file' => ['The document is 1.9 MB, and the most the library takes is 0 MB.']], $this->refusal(fn () => $this->upload($this->file('big.pdf', "%PDF-1.4\n".str_repeat('x', 2_000_000)."\n%%EOF\n"))));
        $this->assertSame(0, DB::table('mainstay_media')->count());

        config(['mainstay.media.documents' => ['pdf']]);
        $this->assertArrayHasKey('file', $this->refusal(fn () => $this->upload($this->docx())), 'The host narrows the list.');
        $this->assertArrayHasKey('file', $this->refusal(fn () => $this->upload($this->png(), ['documents' => ['pdf']])), 'A caller taking documents only takes no image.');

        config(['mainstay.media.documents' => ['pdf', 'exe']]);
        $this->assertThrows(fn () => Documents::allowed(), InvalidArgumentException::class, 'holds no .exe');
    }

    #[Test]
    public function a_file_field_round_trips_through_drafts_and_locales_and_takes_only_what_it_accepts(): void
    {
        $pdf = $this->upload($this->pdf());
        $docx = $this->upload($this->docx());
        $xlsx = $this->upload($this->zip('Sheet.xlsx', ['[Content_Types].xml' => '<Types/>', 'xl/workbook.xml' => '<w/>']));
        $image = $this->upload($this->png(), ['alt' => ['en' => 'Red', 'nl' => 'Rood']]);
        $made = fn (array $data, string $locale = 'en') => Mainstay::create(Brochured::class, ['title' => 'B', 'slug' => 'b-'.++$this->made, ...$data], locale: $locale, overrideAccess: true);

        $entry = $made(['brochure' => $pdf, 'sheet' => $xlsx->id]);
        $read = Mainstay::find(Brochured::class, locale: 'en')->first();
        $this->assertSame([$pdf->id, 'Annual report', $xlsx->id], [$read->brochure->id, $read->brochure->title, $read->sheet->id]);

        $nl = Mainstay::find(Brochured::class, locale: 'nl')->first();
        $this->assertNull($nl?->sheet, 'A localized field is per language.');

        $this->assertSame(['brochure' => ["The brochure field names file {$xlsx->id}, which is not in the media library or is in its trash."]], $this->refusal(fn () => $made(['brochure' => $xlsx])), 'An extension the field does not accept.');
        $this->assertArrayHasKey('brochure', $this->refusal(fn () => $made(['brochure' => $image])), 'An image is no file.');
        $this->assertArrayHasKey('photo', $this->refusal(fn () => $made(['photo' => $pdf])), 'A document is no image.');
        $this->assertArrayHasKey('brochure', $this->refusal(fn () => $made(['brochure' => 999])));

        $draft = Mainstay::drafts()->save(Brochured::class, ['brochure' => $docx->id], entry: $entry->id, locale: 'en', overrideAccess: true);
        $this->assertNotNull($draft);

        Mainstay::media()->delete($pdf->id, overrideAccess: true);
        $trashed = Mainstay::find(Brochured::class, locale: 'en')->first();
        $this->assertTrue($trashed->brochure->missing);
        $this->assertSame($pdf->id, $trashed->brochure->id, 'Kept while it is away.');

        $this->assertThrows(fn () => new File(accept: ['exe']), InvalidArgumentException::class, 'holds no .exe');
    }

    #[Test]
    public function the_api_hands_a_file_out_with_its_address_and_the_schema_describes_it(): void
    {
        $pdf = $this->upload($this->pdf());
        $secret = $this->token();
        Mainstay::create(Brochured::class, ['title' => 'B', 'slug' => 'b', 'brochure' => $pdf], locale: 'en', overrideAccess: true);

        $entry = $this->getJson('/api/mainstay/entries/brochured', ['Authorization' => "Bearer {$secret}"])->assertOk()->json('data.0.brochure');
        $this->assertSame(['id' => $pdf->id, 'name' => 'Report.pdf', 'type' => 'application/pdf', 'bytes' => $pdf->bytes, 'title' => 'Annual report', 'description' => null, 'visibility' => 'public', 'url' => route('mainstay.media.file', "{$pdf->hash}.pdf")], $entry);

        Mainstay::media()->update($pdf->id, visibility: 'restricted', overrideAccess: true);
        $restricted = $this->getJson('/api/mainstay/entries/brochured', ['Authorization' => "Bearer {$secret}"])->json('data.0.brochure');
        $this->assertStringContainsString('/media/s/'.$pdf->hash.'.pdf?', $restricted['url']);
        $this->assertStringContainsString('signature=', $restricted['url']);

        $schema = $this->getJson('/api/mainstay/schema', ['Authorization' => "Bearer {$secret}"])->assertOk()->json();
        $this->assertSame('File', $schema['$defs']['File']['title']);
        $this->assertSame(['$ref' => '#/$defs/File'], $schema['$defs']['brochured']['properties']['brochure']['anyOf'][1]);
        $this->artisan('mainstay:typescript', ['path' => 'storage/framework/testing/mainstay.d.ts'])->assertSuccessful();
        $this->assertStringContainsString('export interface File', file_get_contents(base_path('storage/framework/testing/mainstay.d.ts')));
    }

    private function token(): string
    {
        $user = $this->editor();

        return Mainstay::tokens()->issue($user->id, 'Build', [], overrideAccess: true)[1];
    }

    #[Test]
    public function a_public_file_is_served_to_anyone_with_long_cache_headers_and_answers_a_conditional_request(): void
    {
        $pdf = $this->upload($this->file('Jaarverslag – één.pdf', "%PDF-1.4\n%%EOF\n"));
        $url = route('mainstay.media.file', "{$pdf->hash}.pdf");
        $this->assertSame("http://localhost/media/{$pdf->hash}.pdf", $url);

        $response = $this->browser()->get($url)->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame("default-src 'none'; sandbox", $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('max-age=31536000', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('public', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('immutable', $response->headers->get('Cache-Control'));
        $this->assertSame('', (string) $response->headers->get('Set-Cookie'), 'A visitor gets no session.');
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment;', $disposition);
        $this->assertStringContainsString("filename*=utf-8''Jaarverslag%20%E2%80%93%20%C3%A9%C3%A9n.pdf", $disposition);
        $this->assertStringContainsString('filename=Jaarverslag_-_een.pdf', $disposition);

        $this->browser()->get($url, ['If-None-Match' => $response->headers->get('ETag')])->assertStatus(304);
        $this->browser()->get($url, ['Range' => 'bytes=0-3'])->assertStatus(206);
    }

    #[Test]
    public function an_image_copy_is_served_inline_through_the_route_and_its_original_never(): void
    {
        $image = $this->upload($this->png(), ['alt' => ['en' => 'Red', 'nl' => 'Rood']]);
        $read = Mainstay::create(Brochured::class, ['title' => 'B', 'slug' => 'b', 'photo' => $image], locale: 'en', overrideAccess: true)->photo;
        $url = $read->url('card', 'webp');

        $this->assertStringStartsWith("http://localhost/media/{$image->hash}-40", $url);
        $this->assertStringNotContainsString('/storage/', $url);
        $response = $this->browser()->get($url)->assertOk();
        $this->assertSame('image/webp', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        $this->browser()->get(route('mainstay.media.file', "{$image->hash}.png"))->assertNotFound();

        /* Even where the originals share the media disk. */
        config(['mainstay.media.originals' => 'media']);
        $shared = $this->upload($this->file('other.png', (string) file_get_contents($this->png()).'x'), ['alt' => ['en' => 'a', 'nl' => 'b']]);
        $this->assertTrue(Storage::disk('media')->exists("media/{$shared->hash}.png"));
        $this->browser()->get(route('mainstay.media.file', "{$shared->hash}.png"))->assertNotFound();
        $this->browser()->get(route('mainstay.media.file', "{$image->hash}-999.webp"))->assertNotFound();
        $this->assertSame(['sizes' => true], ['sizes' => str_contains(json_encode(Mainstay::find(Brochured::class, locale: 'en')->first()->photo->toArray(), JSON_UNESCAPED_SLASHES), "/media/{$image->hash}-40")]);
    }

    #[Test]
    public function a_restricted_file_is_for_a_signed_in_editor_or_a_signed_url_and_nobody_else(): void
    {
        $pdf = $this->upload($this->pdf(), ['visibility' => 'restricted']);
        $plain = route('mainstay.media.file', "{$pdf->hash}.pdf");
        $jar = $this->signedIn();

        $this->browser()->get($plain)->assertNotFound();
        $this->assertStringContainsString('no-store', $this->browser()->get($plain)->headers->get('Cache-Control'), 'A refusal is cached by nobody either.');
        $this->browser(['mainstay_session' => 'forged'])->get($plain)->assertNotFound();

        $served = $this->browser($jar)->get($plain)->assertOk();
        $this->assertSame('no-store, private', $served->headers->get('Cache-Control'));

        $signed = Mainstay::media()->find($pdf->id)->temporaryUrl();
        $this->assertSame($signed, Mainstay::media()->find($pdf->id)->url(), 'A restricted file prints a signed address.');
        $this->browser()->get($signed)->assertOk();
        $this->assertSame('no-store, private', $this->browser()->get($signed)->headers->get('Cache-Control'));
        $this->browser()->get($signed.'0')->assertForbidden();
        $this->browser()->get(str_replace($pdf->hash, str_repeat('a', 64), $signed))->assertForbidden();

        $this->travel(2)->hours();
        $this->browser()->get($signed)->assertForbidden();
        $this->travelBack();

        $short = Mainstay::media()->find($pdf->id)->temporaryUrl(expires: CarbonImmutable::now()->addMinute());
        $this->browser()->get($short)->assertOk();
        $this->travel(2)->minutes();
        $this->browser()->get($short)->assertForbidden();
        $this->travelBack();

        Mainstay::media()->update($pdf->id, visibility: 'public', overrideAccess: true);
        $this->browser()->get($plain)->assertOk();
        $editors = $this->browser($jar)->get($plain)->assertOk();
        $this->assertStringContainsString('public', $editors->headers->get('Cache-Control'));
        $this->assertSame([], $editors->headers->getCookies(), 'No cookie rides on a cacheable response.');

        Mainstay::media()->delete($pdf->id, overrideAccess: true);
        $this->browser()->get($plain)->assertNotFound();
        $this->browser($jar)->get($plain)->assertOk();
        $this->browser()->get($signed)->assertNotFound();
    }

    #[Test]
    public function the_route_is_one_name_on_every_host_and_an_entry_cannot_take_its_path(): void
    {
        $this->assertSame('/media/{file}', '/'.Route::getRoutes()->getByName('mainstay.media.file')->uri());
        $this->assertNull(Route::getRoutes()->getByName('mainstay.media.file')->getDomain());

        $pdf = $this->upload($this->pdf());
        $this->get("http://other.example/media/{$pdf->hash}.pdf")->assertOk();

        $shadow = app(Addresses::class)->shadow('en', "/media/{$pdf->hash}.pdf");
        $this->assertSame('the route mainstay.media.file', $shadow);
        $this->assertSame('the route mainstay.media.signed', app(Addresses::class)->shadow('en', '/media/s/'.$pdf->hash.'.pdf'));
        $this->assertNull(app(Addresses::class)->shadow('en', '/media/news'), 'A path the route does not match is the entry\'s.');

        foreach (['../x.pdf', 'ABC.pdf', str_repeat('a', 63).'.pdf', str_repeat('a', 64).'.pdf.php', str_repeat('a', 64).'-1x.pdf'] as $name) {
            $this->get('/media/'.$name)->assertNotFound();
        }
    }

    #[Test]
    public function the_admin_lists_documents_filters_by_kind_and_edits_a_documents_title_and_visibility(): void
    {
        $jar = $this->signedIn();
        $pdf = $this->upload($this->pdf(), ['visibility' => 'restricted']);
        $image = $this->upload($this->png(), ['alt' => ['en' => 'Red', 'nl' => 'Rood']]);
        $docx = $this->upload($this->docx());

        $all = $this->browser($jar)->get(route('mainstay.media'))->assertOk();
        $all->assertSee('Report.pdf')->assertSee('Restricted')->assertSee('Documents')->assertSee('Images');
        $this->assertStringNotContainsString('<img src="'.route('mainstay.media.file', "{$pdf->hash}.pdf"), $all->getContent());

        $docs = $this->browser($jar)->get(route('mainstay.media', ['kind' => 'document']))->assertOk();
        $docs->assertSee('Report.pdf')->assertSee('Letter.docx')->assertDontSee('Report.png');
        $this->assertSame(0, substr_count($docs->getContent(), '<img src="http://localhost/media/'));

        $pick = $this->browser($jar)->get(route('mainstay.media', ['pick' => 1, 'kind' => 'document', 'accept' => 'pdf']))->assertOk();
        $pick->assertSee('data-pick="'.$pdf->id.'"', false)->assertDontSee('data-pick="'.$docx->id.'"', false)->assertDontSee('data-pick="'.$image->id.'"', false)->assertSee('data-name="Annual report"', false);
        $this->assertStringContainsString('accept="image/jpeg', $this->browser($jar)->get(route('mainstay.media', ['pick' => 1, 'kind' => 'image']))->assertOk()->assertDontSee('data-pick="'.$pdf->id.'"', false)->getContent());
        $this->assertStringContainsString('accept=".pdf"', $pick->getContent());

        $edit = $this->browser($jar)->get(route('mainstay.media.edit', $pdf->id))->assertOk();
        $edit->assertSee('Download')->assertDontSee('data-focal-image', false)->assertSee('name="title[nl]"', false)->assertSee('name="visibility"', false);

        $this->browser($jar)->post(route('mainstay.media.update', $pdf->id), ['title' => ['en' => 'Report', 'nl' => 'Verslag'], 'description' => ['en' => 'Yearly'], 'visibility' => 'public'])->assertSessionHasNoErrors();
        $changed = Mainstay::media()->find($pdf->id, 'nl');
        $this->assertSame(['Verslag', 'public', null], [$changed->title, $changed->visibility, $changed->description]);
        $this->assertSame('Yearly', Mainstay::media()->find($pdf->id, 'en')->description);

        $this->browser($jar)->post(route('mainstay.media.update', $pdf->id), ['visibility' => 'secret'])->assertSessionHasErrors('visibility');
        $this->browser($jar)->post(route('mainstay.media.update', $pdf->id), ['focal' => [10, 10]])->assertSessionHasErrors('file');

        $this->browser($jar)->post(route('mainstay.media.store'), ['file' => UploadedFile::fake()->create('plan.pdf', 4, 'application/pdf'), 'visibility' => 'restricted'])->assertSessionHasErrors('file');
        $stored = $this->browser($jar)->post(route('mainstay.media.store', ['pick' => 1, 'kind' => 'document', 'accept' => 'pdf']), ['file' => $this->uploaded($this->pdf('two'), 'two.pdf'), 'title' => ['en' => 'Two']])->assertOk();
        $this->assertSame('public', DB::table('mainstay_media')->where('name', 'two.pdf')->value('visibility'));
        $stored->assertSee('data-picked', false);
        $refused = $this->browser($jar)->post(route('mainstay.media.store', ['pick' => 1, 'kind' => 'document', 'accept' => 'pdf']), ['file' => $this->uploaded($this->docx(), 'x.docx')])->assertStatus(422);
        $refused->assertSee('not one of the allowed documents');
    }

    #[Test]
    public function the_entry_form_draws_a_file_field_with_its_picker_and_saves_the_id(): void
    {
        $jar = $this->signedIn();
        $pdf = $this->upload($this->pdf());

        $form = $this->browser($jar)->get(route('mainstay.entries.create', 'brochured'))->assertOk();
        $form->assertSee('data-file-field', false)->assertSee('No file')->assertSee('name="brochure"', false);
        $this->assertStringContainsString('kind=document&amp;accept=pdf%2Cdocx', $form->getContent());
        $this->assertStringContainsString('kind=image', $form->getContent());

        $this->browser($jar)->post(route('mainstay.entries.store', 'brochured'), ['title' => 'B', 'slug' => 'b', 'brochure' => (string) $pdf->id])->assertSessionHasNoErrors();
        $draft = Mainstay::drafts()->all(Brochured::class, locale: 'en')->sole();

        $this->browser($jar)->get(route('mainstay.drafts.edit', ['brochured', $draft->id]))->assertSee('Annual report')->assertSee('value="'.$pdf->id.'"', false);
    }

    #[Test]
    public function a_file_or_image_in_the_trash_prints_nothing_and_never_throws_through_either_field(): void
    {
        $pdf = $this->upload($this->pdf());
        $image = $this->upload($this->png(), ['alt' => ['en' => 'Red', 'nl' => 'Rood']]);
        $entry = Mainstay::create(Brochured::class, ['title' => 'B', 'slug' => 'b', 'brochure' => $pdf, 'photo' => $image], locale: 'en', overrideAccess: true);
        Mainstay::drafts()->save(Brochured::class, ['title' => 'Draft', 'brochure' => $pdf->id, 'photo' => $image->id], entry: $entry->id, locale: 'en', overrideAccess: true);
        Mainstay::media()->delete($pdf->id, overrideAccess: true);
        Mainstay::media()->delete($image->id, overrideAccess: true);

        $read = Mainstay::find(Brochured::class, locale: 'en')->first();

        foreach ([$read->brochure, $read->photo] as $missing) {
            $this->assertTrue($missing->missing);
            $this->assertNull($missing->url('card'));
            $this->assertNull($missing->url());
            $this->assertNull($missing->temporaryUrl());
            $this->assertSame('', (string) $missing->picture('card'));
        }

        $this->assertSame('', Blade::render('{{ $media->url() }}', ['media' => $read->brochure]));
        $this->assertSame('', Blade::render('{{ $media->url(\'card\') }}', ['media' => $read->photo]));

        $secret = $this->token();
        $payload = $this->getJson('/api/mainstay/entries/brochured', ['Authorization' => "Bearer {$secret}"])->assertOk()->json('data.0');
        $this->assertSame([$pdf->id, $image->id], [$payload['brochure'], $payload['photo']], 'The id alone, as a missing image always was.');

        $draft = Mainstay::drafts()->of(Brochured::class, $entry->id, locale: 'en', overrideAccess: true);
        $this->assertTrue($draft->entry->brochure->missing);
        $this->assertTrue($draft->entry->photo->missing);
    }

    #[Test]
    public function an_install_that_has_not_moved_its_files_keeps_serving_them_from_the_legacy_disk_by_the_same_rules(): void
    {
        Log::spy();
        $pdf = $this->upload($this->pdf(), ['visibility' => 'restricted']);
        $bytes = Storage::disk('media')->get("media/{$pdf->hash}.pdf");
        Storage::disk('media')->delete("media/{$pdf->hash}.pdf");
        Storage::disk('public')->put("media/{$pdf->hash}.pdf", $bytes);
        $plain = route('mainstay.media.file', "{$pdf->hash}.pdf");

        $this->browser()->get($plain)->assertNotFound();
        $served = $this->browser($this->signedIn())->get($plain)->assertOk();
        $this->assertSame('no-store, private', $served->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $served->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('attachment', $served->headers->get('Content-Disposition'));
        $this->browser()->get(Mainstay::media()->find($pdf->id)->temporaryUrl())->assertOk();
        $this->browser()->get(Mainstay::media()->find($pdf->id)->temporaryUrl())->assertOk();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'mainstay:media:move'))->once();

        Cache::forget('mainstay.media.legacy-warned');
        $this->browser()->get(Mainstay::media()->find($pdf->id)->temporaryUrl())->assertOk();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'mainstay:media:move'))->twice();

        Mainstay::media()->update($pdf->id, visibility: 'public', overrideAccess: true);
        $this->assertStringContainsString('immutable', $this->browser()->get($plain)->assertOk()->headers->get('Cache-Control'));

        config(['mainstay.media.legacy_disk' => null]);
        $this->browser()->get($plain)->assertNotFound();
    }

    #[Test]
    public function a_legacy_disk_the_host_never_configured_is_a_404_and_a_cache_that_is_down_costs_only_the_warning(): void
    {
        $pdf = $this->upload($this->pdf());
        $url = route('mainstay.media.file', "{$pdf->hash}.pdf");
        $bytes = Storage::disk('media')->get("media/{$pdf->hash}.pdf");
        Storage::disk('media')->delete("media/{$pdf->hash}.pdf");

        config(['mainstay.media.legacy_disk' => 'ghost']);
        $this->browser()->get($url)->assertNotFound();

        config(['mainstay.media.legacy_disk' => 'public']);
        Storage::disk('public')->put("media/{$pdf->hash}.pdf", $bytes);
        Log::spy();
        Cache::shouldReceive('add')->andThrow(new RuntimeException('cache is down'));
        $this->browser()->get($url)->assertOk();
        Log::shouldNotHaveReceived('warning');
    }

    #[Test]
    public function the_move_command_reads_the_legacy_disk_unless_told_another(): void
    {
        Storage::fake('old');
        config(['mainstay.media.legacy_disk' => 'old']);
        Storage::disk('old')->put('media/'.str_repeat('d', 64).'-60.webp', 'bytes');
        Storage::disk('public')->put('media/'.str_repeat('e', 64).'-60.webp', 'other');

        $this->artisan('mainstay:media:move')->expectsOutputToContain('Copied 1, already there 0')->assertSuccessful();
        $this->assertTrue(Storage::disk('media')->exists('media/'.str_repeat('d', 64).'-60.webp'));
        $this->assertFalse(Storage::disk('media')->exists('media/'.str_repeat('e', 64).'-60.webp'));

        $this->artisan('mainstay:media:move', ['--from' => 'public'])->expectsOutputToContain('Copied 1')->assertSuccessful();
    }

    #[Test]
    public function the_move_command_detects_a_copy_that_is_not_the_size_of_its_source_and_copies_it_again(): void
    {
        $name = 'media/'.str_repeat('c', 64).'-640.webp';
        Storage::disk('public')->put($name, 'webp-bytes-in-full');
        $fake = Storage::disk('media');
        Storage::set('media', new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig()) extends FilesystemAdapter
        {
            public function writeStream($path, $resource, array $options = [])
            {
                $short = fopen('php://memory', 'w+');
                fwrite($short, 'we');
                rewind($short);

                return parent::writeStream($path, $short, $options);
            }
        });

        $this->assertThrows(fn () => $this->artisan('mainstay:media:move')->run(), RuntimeException::class, 'is not on the media disk at its size');

        Storage::set('media', $fake);
        $this->artisan('mainstay:media:move')->expectsOutputToContain('Copied 1, already there 0')->assertSuccessful();
        $this->assertSame('webp-bytes-in-full', Storage::disk('media')->get($name));
    }

    #[Test]
    public function a_name_is_stripped_of_control_characters_and_separators_before_it_is_kept(): void
    {
        $pdf = $this->upload($this->file("Re\x07po\x1Brt\\x.pdf", "%PDF-1.4\n%%EOF\n"));

        $this->assertSame('Reportx.pdf', $pdf->name);
    }

    private function uploaded(string $path, string $name): UploadedFile
    {
        return new UploadedFile($path, $name, null, null, true);
    }

    #[Test]
    public function a_user_without_upload_media_cannot_change_a_files_visibility(): void
    {
        $pdf = $this->upload($this->pdf());
        $role = Role::query()->create(['name' => 'reader', 'capabilities' => []]);
        $reader = User::query()->create(['name' => 'Reader', 'email' => 'r@example.com', 'password' => 'correct horse battery', 'role_id' => $role->id]);
        $this->actingAs($reader, 'mainstay');

        $this->post(route('mainstay.media.update', $pdf->id), ['visibility' => 'restricted'])->assertForbidden();
        $this->assertSame('public', Mainstay::media()->find($pdf->id)->visibility);
    }

    #[Test]
    public function the_move_command_copies_the_public_disks_files_once_verifies_them_and_deletes_nothing(): void
    {
        config(['mainstay.media.disk' => 'media']);
        Storage::disk('public')->put('media/'.str_repeat('a', 64).'-640.webp', 'webp-bytes');
        Storage::disk('public')->put('media/'.str_repeat('b', 64).'-60.avif', 'avif-bytes');
        Storage::disk('media')->put('media/'.str_repeat('b', 64).'-60.avif', 'avif-bytes');

        $this->artisan('mainstay:media:move')->expectsOutputToContain('Copied 1, already there 1')->assertSuccessful();
        $this->assertSame('webp-bytes', Storage::disk('media')->get('media/'.str_repeat('a', 64).'-640.webp'));
        $this->assertCount(2, Storage::disk('public')->allFiles(), 'The public copies stay.');

        $this->artisan('mainstay:media:move')->expectsOutputToContain('Copied 0, already there 2')->assertSuccessful();

        Storage::disk('media')->put('media/'.str_repeat('a', 64).'-640.webp', 'short');
        $this->artisan('mainstay:media:move')->expectsOutputToContain('Copied 1, already there 1')->assertSuccessful();
        $this->assertSame('webp-bytes', Storage::disk('media')->get('media/'.str_repeat('a', 64).'-640.webp'), 'A file of another size is copied again.');
    }

    #[Test]
    public function the_migration_adds_nullable_or_defaulted_columns_of_the_neighbours_types(): void
    {
        $columns = collect(Schema::getColumns('mainstay_media'))->keyBy('name');

        $this->assertFalse($columns['visibility']['nullable']);
        $this->assertSame(true, $columns['title']['nullable']);
        $this->assertSame(true, $columns['description']['nullable']);
        $this->assertSame($columns['alt']['type_name'], $columns['title']['type_name'], 'JSON like alt.');
        $this->assertSame($columns['formats']['type_name'], $columns['visibility']['type_name'], 'A string like formats.');
    }
}
