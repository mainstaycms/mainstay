<?php

namespace Mainstay\PageBuilder\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Mainstay\Auth\Gate;
use Mainstay\Content\Document;
use Mainstay\Content\HoldsPage;
use Mainstay\Fields\Field;
use Mainstay\Fields\Terms;
use Mainstay\Http\Form;
use Mainstay\Mainstay;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use TypeError;

/*
 | What the Content tab asks of the server: the page it draws from the form,
 | and a site block's settings and HTML. Nothing is written; who may edit is
 | what Mainstay::preview() asks of Gate, as a save would.
 */
class CanvasController
{
    public function __construct(private Mainstay $mainstay, private PagedTypes $types, private Visitor $visitor) {}

    /*
     | The page the Content tab draws: the public page of the entry as the
     | form now holds it, unsaved -- Mainstay::preview() of what the form
     | changed, over the entry's draft -- drawn with nobody signed in and its
     | content printed for the editor. A global edited as a page has no view
     | to draw it in, so its content is drawn alone, in the site's editor
     | styles. Only a type with a page has one. What
     | cannot be drawn answers why, as messages for the tab to show: a change
     | refused as its save would be, a field the page needs left empty, a
     | view that throws.
     */
    public function canvas(Request $request, string $type): Response
    {
        return $this->drawCanvas($request, $type);
    }

    public function entryCanvas(Request $request, string $type, int $id): Response
    {
        return $this->drawCanvas($request, $type, id: $id);
    }

    public function draftCanvas(Request $request, string $type, int $draft): Response
    {
        return $this->drawCanvas($request, $type, draft: $draft);
    }

    private function drawCanvas(Request $request, string $type, ?int $id = null, ?int $draft = null): Response
    {
        $class = $this->types->resolve($type);

        $locale = Form::locale($request);
        $fields = Form::fields($class, Gate::about($class)->allows('viewInternal', [$class, $class]));

        try {
            $changes = Form::changes($request, $class, $fields);

            /* A tag typed and not picked is left out: only a save makes
               one. */
            foreach ($fields as $name => $field) {
                if ($field instanceof Terms && is_array($changes[$name] ?? null)) {
                    $changes[$name] = array_values(array_filter($changes[$name], fn (mixed $chip) => ! is_array($chip)));
                }
            }

            $entry = $this->mainstay->preview($class, $id, $changes, draft: $draft, locale: $locale);
        } catch (ValidationException $exception) {
            return response()->json(['messages' => array_values(array_unique(Arr::flatten($exception->errors())))], 422);
        } catch (AuthorizationException|RecordNotFoundException|InvalidArgumentException $exception) {
            return response()->json(['messages' => [$exception->getMessage()]], $exception instanceof AuthorizationException ? 403 : 404);
        }

        /* As phase 8 has it, a draft leaves empty what its property cannot
           hold -- a required date -- and the entry it reads as lacks it. */
        $needed = array_filter($fields, fn (Field $field) => ! $field->internal && ! (new ReflectionProperty($entry, $field->name))->isInitialized($entry));

        if ($needed !== []) {
            return response()->json(['messages' => ['The page is drawn once it has '.implode(', ', array_map(fn (Field $field) => $field->label(), $needed)).'.']], 422);
        }

        $entry->content = ($entry->content ?? Document::empty())->editing();

        return $this->visitor->draw($request, $locale, function () use ($entry, $locale) {
            try {
                $page = $entry instanceof HoldsPage
                    ? view('page_builder::frame', ['content' => $entry->content, 'locale' => $locale, 'style' => $this->mainstay->editorStyle()])
                    : view($this->mainstay->template($entry), ['entry' => $entry]);

                return response($page->render())->header('Cache-Control', 'no-store');
            } catch (Throwable $exception) {
                report($exception);

                return response()->json(['messages' => [$exception->getMessage() ?: 'The page stopped drawing with '.class_basename($exception).'.']], 500);
            }
        });
    }

    /*
     | A site block of the content, for the Content tab: posted as the
     | document holds it, `block`, once it is selected, or as its settings
     | post it after a field changed -- `id`, `type` and `data`, each field
     | through its fromForm() -- with `ancestors`, the attrs of the blocks it
     | sits inside, outermost first, for one inside another. Checked as the
     | canvas checks the content, by Mainstay::preview() as a draft save
     | would, nothing written. Answered with the block as the document is to
     | hold it, its HTML drawn for the editor as the canvas draws it, and,
     | when it is selected, its settings: every field, drawn by the component
     | Details draws its kind with, showing the `errors` posted with it -- a
     | refused save's, by where they are in it. A check that refuses it
     | answers why, with the block as converted and its settings showing each
     | message at its field, what was refused left empty, so it can be put
     | right there; anything else refused answers why alone.
     */
    public function block(Request $request, string $type): JsonResponse
    {
        return $this->drawBlock($request, $type);
    }

    public function entryBlock(Request $request, string $type, int $id): JsonResponse
    {
        return $this->drawBlock($request, $type, id: $id);
    }

    public function draftBlock(Request $request, string $type, int $draft): JsonResponse
    {
        return $this->drawBlock($request, $type, draft: $draft);
    }

    private function drawBlock(Request $request, string $type, ?int $id = null, ?int $draft = null): JsonResponse
    {
        $class = $this->types->resolve($type);
        $content = $this->mainstay->fields($class)['content'];

        $locale = Form::locale($request);
        $drawn = is_string($request->input('block'));
        $ancestors = is_string($request->input('ancestors')) ? json_decode($request->input('ancestors'), true) : [];
        $ancestors = is_array($ancestors) && array_is_list($ancestors) ? $ancestors : [];
        $list = $content->listing();

        foreach ($ancestors as $ancestor) {
            $list = ($held = $list?->block($ancestor)) === null ? null : $this->mainstay->innerBlocks($held);
        }

        $item = $drawn ? json_decode($request->input('block'), true) : ['id' => $request->input('id'), 'type' => $request->input('type'), 'data' => (array) $request->input('data', [])];

        if (($block = $list?->block($item)) === null) {
            return response()->json(['messages' => ['The content holds no block of the type '.json_encode(is_array($item) ? $item['type'] ?? null : null).' here.']], 422);
        }

        $item = $drawn ? $item : $list->itemFromForm($item);
        $node = ['type' => 'block', 'attrs' => $item];

        foreach (array_reverse($ancestors) as $ancestor) {
            $node = ['type' => 'block', 'attrs' => $ancestor, 'content' => [$node]];
        }

        try {
            $entry = $this->mainstay->preview($class, $id, ['content' => ['type' => 'doc', 'content' => [$node]]], draft: $draft, locale: $locale);
        } catch (ValidationException $exception) {
            $prefix = 'content.content.0.'.str_repeat('content.0.', count($ancestors)).'attrs.';
            $errors = Form::blockErrors($block, $exception->errors(), $prefix);

            $shown = $item;
            Arr::forget($shown, array_keys($errors));

            try {
                $read = $drawn ? $list->readItem($shown, 'content') : null;
            } catch (InvalidArgumentException|TypeError) {
                $read = null;
            }

            return response()->json([
                'messages' => array_values(array_unique([...Arr::flatten($errors), ...Arr::flatten(array_filter($exception->errors(), fn (string $key) => ! str_starts_with($key, $prefix), ARRAY_FILTER_USE_KEY))])),
                'block' => $item,
                'form' => $read === null ? null : view('page_builder::block', ['block' => $read, 'messages' => $errors])->render(),
            ], 422);
        } catch (AuthorizationException|RecordNotFoundException|InvalidArgumentException $exception) {
            return response()->json(['messages' => [$exception->getMessage()]], $exception instanceof AuthorizationException ? 403 : 404);
        }

        $read = $entry->content->blocks()[0];
        $attrs = $entry->content->toArray()['content'][0];

        foreach ($ancestors as $ancestor) {
            [$read, $attrs] = [$read->inner()[0], $attrs['content'][0]];
        }

        try {
            $html = $this->visitor->draw($request, $locale, fn () => $this->mainstay->marked(fn () => $read->toHtml()));
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['messages' => [$exception->getMessage() ?: 'The block stopped drawing with '.class_basename($exception).'.']], 500);
        }

        $errors = json_decode((string) $request->input('errors'), true);
        $errors = array_map(fn (array $messages) => array_values(array_filter($messages, is_string(...))), array_filter(is_array($errors) ? $errors : [], is_array(...)));

        return response()->json([
            'block' => $attrs['attrs'],
            'html' => $html,
            'form' => $drawn ? view('page_builder::block', ['block' => $read, 'messages' => $errors])->render() : null,
        ]);
    }
}
