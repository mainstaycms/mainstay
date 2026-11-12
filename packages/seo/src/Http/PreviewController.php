<?php

namespace Mainstay\Seo\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Mainstay\Auth\Gate;
use Mainstay\Content\Entry;
use Mainstay\Http\Form;
use Mainstay\Mainstay;
use Mainstay\Seo\PageText;
use Mainstay\Seo\SeoFields;
use Mainstay\Seo\Visitor;
use Throwable;

/*
 | What the SEO tab asks of the server: the page an entry would be as the
 | form now holds it, unsaved, drawn as a visitor sees it, and its headings,
 | paragraphs, links and images. Nothing is written; who may ask is what
 | Mainstay::preview() asks of Gate, as a save would, beside the type's own.
 */
class PreviewController
{
    public function __construct(private Mainstay $mainstay, private SeoFields $fields, private Visitor $visitor, private PageText $text) {}

    public function create(Request $request, string $type): JsonResponse
    {
        return $this->read($request, $type);
    }

    public function entry(Request $request, string $type, int $id): JsonResponse
    {
        return $this->read($request, $type, id: $id);
    }

    public function draft(Request $request, string $type, int $draft): JsonResponse
    {
        return $this->read($request, $type, draft: $draft);
    }

    private function read(Request $request, string $type, ?int $id = null, ?int $draft = null): JsonResponse
    {
        $class = $this->resolve($type);
        $locale = Form::locale($request);

        if (! $this->mainstay->hasPage($class)) {
            return response()->json(['rendered' => false, 'reason' => 'This type has no page of its own to read.']);
        }

        $fields = Form::fields($class, Gate::about($class)->allows('viewInternal', [$class, $class]));

        try {
            $entry = $this->mainstay->preview($class, $id, Form::changes($request, $class, $fields), draft: $draft, locale: $locale);
        } catch (ValidationException $exception) {
            return response()->json(['rendered' => false, 'reason' => implode(' ', array_unique(Arr::flatten($exception->errors())))], 422);
        } catch (AuthorizationException) {
            return response()->json(['rendered' => false, 'reason' => 'You may not change this.'], 403);
        } catch (RecordNotFoundException|InvalidArgumentException) {
            return response()->json(['rendered' => false, 'reason' => 'There is nothing to preview here.'], 404);
        }

        try {
            $html = $this->visitor->draw($request, $locale, fn () => view($this->mainstay->template($entry), ['entry' => $entry])->render());
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['rendered' => false, 'reason' => 'The page could not be drawn. The error is in the site\'s log.'], 500);
        }

        return response()->json(['rendered' => true, ...$this->text->from($html)])->header('Cache-Control', 'no-store');
    }

    /* The entry type a request names: one that keeps SEO data. Anything else is
       the admin's own not-found. Who may change it is asked by the preview. */
    private function resolve(string $handle): string
    {
        $class = $this->mainstay->registered()[$handle] ?? null;

        if ($class === null || ! is_subclass_of($class, Entry::class) || $this->fields->name($class) === null) {
            throw new HttpResponseException(response()->view('mainstay::missing', status: 404));
        }

        return $class;
    }
}
