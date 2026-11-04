<?php

namespace Mainstay\Mcp;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool as McpTool;
use Mainstay\Api\Operations;
use Mainstay\Api\Schema;
use Mainstay\Auth\Gate;
use Mainstay\Content\Entry;
use Mainstay\Content\GlobalSet;
use Mainstay\Database\BadQuery;

/*
 | One of Operations as a tool. Its answer is the payload as structured
 | content, and a refusal an error result the model can act on: a field that
 | did not take its value by its path, a type or entry that is not there, a
 | capability the token lacks.
 */
abstract class Tool extends McpTool
{
    public function __construct(protected Operations $operations, private Schema $schema) {}

    protected function answer(Closure $operation): Response|ResponseFactory
    {
        try {
            $result = $operation();
        } catch (AuthorizationException $exception) {
            return Response::error(Gate::user() === null ? Operations::NOBODY : $exception->getMessage());
        } catch (ValidationException $exception) {
            return Response::error(collect($exception->errors())->map(fn (array $messages, string $path) => "{$path}: ".implode(' ', $messages))->implode("\n"));
        } catch (BadQuery|RecordNotFoundException $exception) {
            return Response::error($exception->getMessage());
        }

        return $result === null ? Response::text('Done.') : Response::structured($result);
    }

    /* A type's handle, from the ones the caller may read, of a shape:
       built for each request, since that is who asks. */
    protected function type(JsonSchema $schema, ?string $shape = null): Type
    {
        $handles = array_map(fn (string $type) => $type::handle(), array_filter($this->schema->readable(), fn (string $type) => $shape === null || is_subclass_of($type, $shape)));

        $type = $handles === [] ? $schema->string() : $schema->string()->enum(array_values($handles));

        return $type->description(match ($shape) {
            Entry::class => 'The entry type, by its handle.',
            GlobalSet::class => 'The global, by its handle.',
            default => 'The type, by its handle.',
        })->required();
    }

    protected function locale(JsonSchema $schema): Type
    {
        return $schema->string()->description('The locale to read or write in; the site\'s first when left out.');
    }

    protected function depth(JsonSchema $schema): Type
    {
        return $schema->integer()->description('How far to load what relations point at: 0 for their ids alone, 1 when left out, at most '.Operations::DEPTH.'.');
    }

    protected function data(JsonSchema $schema): Type
    {
        return $schema->object()->description('The fields to write, by name, as describe-type\'s input shows them: a relation or an image by its id.')->required();
    }
}
