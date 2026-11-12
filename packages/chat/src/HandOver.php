<?php

namespace Mainstay\Chat;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/*
 | How the everyday model asks for the stronger one. It writes nothing, so it
 | is not Approvable and runs unasked; HandOverMiddleware does the switching
 | on the step after it. Once the turn has been handed over the same tool is
 | still offered, because the history names it, but it only says so.
 */
class HandOver implements Tool
{
    public const NAME = 'hand-over';

    public function __construct(private readonly bool $alreadyHandedOver = false) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Hand this request to a stronger model, which continues it from here. Call it before you change anything when the request is bigger than a quick lookup or one simple edit, or when you are unsure how to do it. It can be used once.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        return $this->alreadyHandedOver
            ? 'This request was already handed over: carry on with it yourself.'
            : 'Handed over. A stronger model continues this request from here: carry on with it.';
    }

    public function afterHandOver(): self
    {
        return new self(alreadyHandedOver: true);
    }
}
