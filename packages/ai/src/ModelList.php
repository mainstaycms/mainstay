<?php

namespace Mainstay\Ai;

/*
 | The models a provider offers for one kind of work, or why there are none
 | to pick from. `note` is written for the screen and never carries what the
 | provider or the client said, which can echo the key.
 */
final class ModelList
{
    /** @param  list<string>  $models */
    public function __construct(public readonly array $models = [], public readonly ?string $note = null) {}

    public static function unavailable(string $note): self
    {
        return new self([], $note);
    }

    public function isPickable(): bool
    {
        return $this->models !== [];
    }
}
