<?php

namespace Mainstay\Http;

use DateTime;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Mainstay\Content\Media;
use Mainstay\Media\Documents;
use Mainstay\Navigation;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/*
 | Every file of the media library, served from its private disk: an image's
 | copy by the name it was written under, or a document by its hash and
 | extension. The name is the route's, constrained to a hash and what may
 | follow it, so no path can be built from it; what is served is what the row
 | says is servable -- never an image's original, which carries its EXIF.
 |
 | A public file goes to anyone and is cached for good: its name holds its
 | hash and every setting of its copy. A restricted one, or one in the trash,
 | is for a signed-in editor, or for a URL signed to expire, and is cached by
 | nobody. Anything else -- a file there is no row for, a restricted one asked
 | for by a stranger -- is the same 404, so which exist is not told.
 */
class MediaFileController
{
    public const FILE = '[0-9a-f]{64}(?:-[0-9]+(?:x[0-9]+-[0-9]+-[0-9]+)?)?\.[a-z0-9]{2,5}';

    private const COPIES = ['avif' => 'image/avif', 'webp' => 'image/webp', 'jpg' => 'image/jpeg', 'png' => 'image/png'];

    public function show(Request $request, string $file): Response
    {
        return $this->serve($request, $file, signed: false);
    }

    public function signed(Request $request, string $file): Response
    {
        return $this->serve($request, $file, signed: true);
    }

    private function serve(Request $request, string $file, bool $signed): Response
    {
        $row = DB::table('mainstay_media')->where('hash', substr($file, 0, 64))->first(['type', 'name', 'visibility', 'deleted_at']);
        $type = $row === null ? null : $this->typeOf($row->type, str_contains($file, '-'), substr($file, strrpos($file, '.') + 1));

        if ($type === null || ! $this->allowed($row, $signed)) {
            return $this->gone();
        }

        $disk = $this->diskHolding("media/{$file}");

        if ($disk === null) {
            return $this->gone();
        }

        $public = $row->visibility === Media::PUBLIC && $row->deleted_at === null;
        $document = ! str_starts_with($row->type, 'image/');
        $response = $this->respond($disk, "media/{$file}");

        $response->headers->set('Content-Type', $type);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            $document ? HeaderUtils::DISPOSITION_ATTACHMENT : HeaderUtils::DISPOSITION_INLINE,
            $row->name,
            $this->fallback($row->name),
        ));

        if ($public) {
            $response->setCache(['public' => true, 'max_age' => 31_536_000, 'immutable' => true]);
            $response->setEtag($file);
            $response->setLastModified(DateTime::createFromFormat('U', (string) $disk->lastModified("media/{$file}")) ?: null);
            $response->isNotModified($request);
        } else {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }

    /*
     | The type to send, or null when the file is not one the row serves: an
     | image's copy is one of the formats written, a document the one file
     | its type has, and nothing else -- the original of an image never.
     */
    private function typeOf(string $stored, bool $copy, string $extension): ?string
    {
        if (str_starts_with($stored, 'image/')) {
            return $copy ? (self::COPIES[$extension] ?? null) : null;
        }

        return ! $copy && Documents::extension($stored) === $extension ? $stored : null;
    }

    private function allowed(object $row, bool $signed): bool
    {
        if ($row->deleted_at !== null && $signed) {
            return false;
        }

        return $signed || ($row->visibility === Media::PUBLIC && $row->deleted_at === null) || Navigation::media('viewRestricted');
    }

    /*
     | The media disk if the file is on it. Failing that, the legacy disk an
     | install upgraded from -- `public` unless `mainstay.media.legacy_disk`
     | says another or null -- so an install that has not run
     | mainstay:media:move yet keeps serving, by the same rules; and says so in
     | the log, once a day, with the command that ends it.
     */
    private function diskHolding(string $path): ?Filesystem
    {
        $disk = Storage::disk(config('mainstay.media.disk', 'local'));

        if ($disk->exists($path)) {
            return $disk;
        }

        $legacy = config('mainstay.media.legacy_disk', 'public');

        if ($legacy === null || $legacy === config('mainstay.media.disk', 'local') || config("filesystems.disks.{$legacy}") === null || ! Storage::disk($legacy)->exists($path)) {
            return null;
        }

        if ($this->firstWarningToday()) {
            Log::warning("Media is being served from the {$legacy} disk, where an earlier Mainstay kept it. Run `php artisan mainstay:media:move`, then delete media/ from the {$legacy} disk.");
        }

        return Storage::disk($legacy);
    }

    /* Best effort: a cache store that is down costs the warning, never the
       file. */
    private function firstWarningToday(): bool
    {
        try {
            return Cache::add('mainstay.media.legacy-warned', true, now()->addDay());
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /* A local disk's file goes by a path, which gives ranges and lets a
       proxy deliver it; any other disk is streamed. */
    private function respond(Filesystem $disk, string $path): Response
    {
        if (config('mainstay.media.sendfile')) {
            BinaryFileResponse::trustXSendfileTypeHeader();
        }

        return $disk instanceof FilesystemAdapter && $disk->getAdapter() instanceof LocalFilesystemAdapter
            ? new BinaryFileResponse($disk->path($path), autoEtag: false, autoLastModified: false)
            : $disk->response($path);
    }

    private function gone(): Response
    {
        return response('Not found.', 404, ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /* Header values are ASCII, and `%` and `\` are not allowed in the
       fallback; the full name goes in filename*=UTF-8''. */
    private function fallback(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', Str::ascii($name)) ?? 'file';
    }
}
