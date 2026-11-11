<?php

namespace Mainstay\Chat;

use RuntimeException;
use Throwable;

/*
 | What the log keeps of a provider's failure: which kind, the status and
 | whose turn it was, never the exception itself. A provider's error body can
 | echo the key it refused or the prompt it was sent, and the original's
 | message, trace and previous chain all carry it, so none is passed on.
 */
class ProviderFailed extends RuntimeException
{
    private function __construct(private readonly string $failureClass, private readonly ?int $status, private readonly ?string $conversation, private readonly int|string $user)
    {
        parent::__construct('The chat provider failed.');
    }

    public static function from(Throwable $failure, ?string $conversation, int|string $user): self
    {
        return new self($failure::class, ProviderFailure::statusOf($failure), $conversation, $user);
    }

    /** @return array{failure: string, status: int|null, conversation: string|null, user: int|string} */
    public function context(): array
    {
        return ['failure' => $this->failureClass, 'status' => $this->status, 'conversation' => $this->conversation, 'user' => $this->user];
    }
}
