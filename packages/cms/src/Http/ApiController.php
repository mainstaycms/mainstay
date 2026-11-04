<?php

namespace Mainstay\Http;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use JsonException;
use Mainstay\Api\Operations;
use Mainstay\Auth\Gate;
use Mainstay\Database\BadQuery;

/*
 | The API over HTTP: each route one of Operations, its arguments from the
 | query string for a read and from the JSON body for a write -- `data`,
 | keyed by the fields' names, and `locale` -- and every refusal answered
 | as JSON with its status.
 */
class ApiController
{
    public function __construct(private Operations $operations) {}

    public function schema(): JsonResponse
    {
        return $this->answer(fn () => $this->operations->schema());
    }

    public function types(): JsonResponse
    {
        return $this->answer(fn () => ['data' => $this->operations->types()]);
    }

    public function type(string $type): JsonResponse
    {
        return $this->answer(fn () => $this->operations->describe($type));
    }

    /* `where` is the query layer's own, as JSON: `{"status": "live",
       "readingMinutes": {">": 3}}`. */
    public function index(Request $request, string $type): JsonResponse
    {
        return $this->answer(fn () => $this->operations->find(
            $type,
            where: $this->json($request->query('where')),
            sort: (string) $request->query('sort', ''),
            page: $this->integer($request, 'page') ?? 1,
            perPage: $this->integer($request, 'perPage') ?? 15,
            locale: $request->query('locale'),
            depth: $this->integer($request, 'depth'),
        ));
    }

    public function show(Request $request, string $type, int $id): JsonResponse
    {
        return $this->answer(fn () => $this->operations->get($type, $id, $request->query('locale'), $this->integer($request, 'depth')));
    }

    public function path(Request $request): JsonResponse
    {
        return $this->answer(fn () => $this->operations->path((string) $request->query('uri', ''), $request->query('locale'), $this->integer($request, 'depth')));
    }

    public function store(Request $request, string $type): JsonResponse
    {
        return $this->answer(fn () => $this->operations->create($type, $this->data($request), $request->input('locale')), 201);
    }

    public function update(Request $request, string $type, int $id): JsonResponse
    {
        return $this->answer(fn () => $this->operations->update($type, $id, $this->data($request), $request->input('locale')));
    }

    public function destroy(string $type, int $id): JsonResponse|Response
    {
        return $this->answer(fn () => $this->operations->trash($type, $id));
    }

    public function global(Request $request, string $type): JsonResponse
    {
        return $this->answer(fn () => $this->operations->global($type, $request->query('locale'), $this->integer($request, 'depth')));
    }

    public function saveGlobal(Request $request, string $type): JsonResponse
    {
        return $this->answer(fn () => $this->operations->saveGlobal($type, $this->data($request), $request->input('locale')));
    }

    public function draftOf(Request $request): JsonResponse
    {
        return $this->answer(fn () => $this->operations->draftOf((string) $request->query('type', ''), $this->integer($request, 'entry'), $request->query('locale')));
    }

    public function draft(Request $request, int $id): JsonResponse
    {
        return $this->answer(fn () => $this->operations->draft($id, $request->query('locale')));
    }

    /* None at all, where the draft is left changing nothing. */
    public function saveDraft(Request $request): JsonResponse|Response
    {
        return $this->answer(fn () => $this->operations->saveDraft(
            (string) $request->input('type', ''),
            $this->data($request),
            $this->integer($request, 'entry'),
            $this->integer($request, 'draft'),
            $request->input('locale'),
        ));
    }

    public function publish(int $id): JsonResponse
    {
        return $this->answer(fn () => $this->operations->publish($id));
    }

    public function discard(int $id): JsonResponse|Response
    {
        return $this->answer(fn () => $this->operations->discard($id));
    }

    /* The operation's answer, or its refusal: nobody refused is told a
       token would help, as Bearer asks. */
    private function answer(Closure $operation, int $status = 200): JsonResponse|Response
    {
        try {
            $result = $operation();
        } catch (AuthorizationException $exception) {
            return Gate::user() === null
                ? response()->json(['message' => Operations::NOBODY], 401, ['WWW-Authenticate' => 'Bearer'])
                : response()->json(['message' => $exception->getMessage()], 403);
        } catch (RecordNotFoundException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        } catch (ValidationException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'errors' => $exception->errors()], 422);
        } catch (BadQuery $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return $result === null ? response()->noContent() : response()->json($result, $status);
    }

    /* What to write: an object keyed by the fields' names. */
    private function data(Request $request): array
    {
        $data = $request->input('data');

        return is_array($data) && ($data === [] || ! array_is_list($data))
            ? $data
            : throw new BadQuery('Send what to write as `data`: an object keyed by the fields\' names.');
    }

    private function json(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        try {
            $decoded = is_string($value) ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : null;
        } catch (JsonException $exception) {
            throw new BadQuery("`where` is not JSON: {$exception->getMessage()}.");
        }

        return is_array($decoded) ? $decoded : throw new BadQuery('`where` is a JSON object of conditions by field.');
    }

    /* A whole number from the query string or the body, or null where none
       is given. */
    private function integer(Request $request, string $key): ?int
    {
        $value = $request->input($key);

        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : throw new BadQuery("`{$key}` is a whole number; ".json_encode($value).' is not one.');
    }
}
