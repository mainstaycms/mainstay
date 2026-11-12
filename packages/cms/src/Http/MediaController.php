<?php

namespace Mainstay\Http;

use Illuminate\Database\RecordNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Mainstay\Content\Media;
use Mainstay\Mainstay;
use Mainstay\Media\Documents;

/*
 | The media library's screens over the library: the grid and its trash, an
 | upload, and a page for an image's alt text and focal point or a
 | document's title and description, and either's visibility. The library
 | asks Gate on every write, as it does for a seeder, so a refusal is its own.
 |
 | The grid is also the image and file fields' picker: `?pick` draws it
 | without the shell, for the dialog a field opens, `kind=image` or
 | `kind=document&accept=pdf,docx` showing only what the field takes, and an
 | upload from there answers with the grid again rather than a page. An image's page is the
 | Content tab's image dialog too: `?dialog` draws its form alone, which
 | posts for JSON.
 */
class MediaController
{
    private const PER_PAGE = 24;

    public function __construct(private Mainstay $mainstay) {}

    public function index(Request $request): View
    {
        return $this->grid($request, trashed: false);
    }

    public function trashed(Request $request): View
    {
        return $this->grid($request, trashed: true);
    }

    /*
     | A file and its alt text in every locale. An alt left empty posts the
     | null the host's middleware makes of it, which is the empty string the
     | library takes as decorative.
     */
    public function store(Request $request): View|RedirectResponse
    {
        $file = $request->file('file');

        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            $this->refuse($request, ['file' => $file instanceof UploadedFile ? $file->getErrorMessage() : 'Choose a file to upload.']);
        }

        try {
            $media = $this->mainstay->media()->upload(
                $file,
                $this->alt($request),
                title: $this->locales($request, 'title'),
                description: $this->locales($request, 'description'),
                visibility: $request->input('visibility') === Media::RESTRICTED ? Media::RESTRICTED : Media::PUBLIC,
                documents: $this->filter($request)['kind'] === 'document' ? $this->filter($request)['accept'] ?? Documents::allowed() : null,
            );
        } catch (ValidationException $exception) {
            $this->refuse($request, $exception->errors());
        }

        return $request->has('pick')
            ? $this->grid($request, trashed: false, picked: $media->id)
            : redirect()->route('mainstay.media.edit', $media->id)->with('status', 'Uploaded.');
    }

    public function edit(Request $request, int $id): View
    {
        $locales = array_keys($this->mainstay->locales());
        $media = $this->mainstay->media()->find($id) ?? throw $this->missing();

        $in = fn (string $property) => array_combine($locales, array_map(fn (string $locale) => $this->mainstay->media()->find($id, $locale)?->{$property}, $locales));

        return view($request->has('dialog') ? 'mainstay::media.dialog' : 'mainstay::media.edit', [
            'media' => $media,
            'alt' => $in('alt'),
            'title' => $in('title'),
            'description' => $in('description'),
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse|JsonResponse
    {
        $focal = $request->input('focal');

        $visibility = $request->input('visibility');

        $this->found(fn () => $this->mainstay->media()->update(
            $id,
            alt: $request->has('alt') ? $this->alt($request) : null,
            focal: is_array($focal) ? array_values($focal) : null,
            title: $request->has('title') ? $this->locales($request, 'title') : null,
            description: $request->has('description') ? $this->locales($request, 'description') : null,
            visibility: is_string($visibility) ? $visibility : null,
        ));

        return $request->expectsJson()
            ? response()->json(['status' => 'Saved.'])
            : redirect()->route('mainstay.media.edit', $id)->with('status', 'Saved.');
    }

    public function trash(int $id): RedirectResponse
    {
        $this->found(fn () => $this->mainstay->media()->delete($id));

        return redirect()->route('mainstay.media')->with('status', 'Moved to the trash.');
    }

    public function restore(int $id): RedirectResponse
    {
        $this->found(fn () => $this->mainstay->media()->restore($id));

        return redirect()->route('mainstay.media.trash')->with('status', 'Restored.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $this->found(fn () => $this->mainstay->media()->destroy($id));

        return redirect()->route('mainstay.media.trash')->with('status', 'Deleted for good.');
    }

    private function grid(Request $request, bool $trashed, ?int $picked = null): View
    {
        $page = $request->query('page');
        $filter = $this->filter($request);
        $images = $this->mainstay->media()->paginate(self::PER_PAGE, page: is_string($page) && ctype_digit($page) ? (int) $page : null, trashed: $trashed, kind: $filter['kind'], accept: $filter['accept']);

        return view($request->has('pick') ? 'mainstay::media.picker' : 'mainstay::media.index', [
            'images' => $images,
            'trashed' => $trashed,
            'picked' => $picked,
            'locales' => $this->mainstay->locales(),
            'filter' => $filter,
        ]);
    }

    /* An image the library does not hold, in the trash or out of it, is the
       admin's not-found rather than the site's. */
    private function found(callable $write): void
    {
        try {
            $write();
        } catch (RecordNotFoundException) {
            throw $this->missing();
        }
    }

    private function missing(): HttpResponseException
    {
        return new HttpResponseException(response()->view('mainstay::missing', status: 404));
    }

    /*
     | What the grid shows: `kind` is image or document, and `accept` the
     | extensions a file field takes -- the library's own, so what a query
     | string says is only ever one of those.
     |
     | @return array{kind: ?string, accept: ?list<string>}
     */
    private function filter(Request $request): array
    {
        $kind = $request->input('kind');
        $accept = $request->input('accept');
        $extensions = is_string($accept) ? array_values(array_intersect(explode(',', $accept), Documents::known())) : [];

        return [
            'kind' => in_array($kind, ['image', 'document'], true) ? $kind : null,
            'accept' => $extensions === [] ? null : $extensions,
        ];
    }

    /* A text posted by locale, the null an empty input posts as the empty
       string, a locale not posted left out. */
    private function locales(Request $request, string $name): array
    {
        $posted = $request->input($name);

        return array_map(fn (mixed $text) => $text ?? '', array_intersect_key(is_array($posted) ? $posted : [], $this->mainstay->locales()));
    }

    /* The alt text posted, by locale: the null an empty input posts as the
       empty string, and a locale not posted left out, for the library to
       refuse on an upload. */
    private function alt(Request $request): array
    {
        return $this->locales($request, 'alt');
    }

    /* Back to the form with what was wrong, or, from the picker, the picker
       again with it. */
    private function refuse(Request $request, array $errors): never
    {
        if ($request->has('pick')) {
            throw new HttpResponseException(response()->view('mainstay::media.picker', [
                'images' => $this->mainstay->media()->paginate(self::PER_PAGE, kind: $this->filter($request)['kind'], accept: $this->filter($request)['accept']),
                'filter' => $this->filter($request),
                'trashed' => false,
                'picked' => null,
                'locales' => $this->mainstay->locales(),
                'refused' => collect($errors)->flatten()->all(),
            ], 422));
        }

        throw ValidationException::withMessages($errors);
    }
}
