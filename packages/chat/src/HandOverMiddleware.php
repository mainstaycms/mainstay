<?php

namespace Mainstay\Chat;

use Closure;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Responses\Data\ToolCall;

/*
 | Runs every step of a turn on the stronger model once the turn holds a
 | hand-over, so the same loop, stream and stored message carry on. The
 | turn is read from the history, not remembered anywhere: an approval resume
 | rebuilds the same history, so it continues on the model that asked for the
 | write. The next message starts on the everyday model again.
 */
class HandOverMiddleware
{
    public function __construct(private readonly string $strongerModel) {}

    public function handle(PendingStep $step, Closure $next): mixed
    {
        if (! $this->turnWasHandedOver($step->messages)) {
            return $next($step);
        }

        $tools = array_map(fn ($tool) => $tool instanceof HandOver ? $tool->afterHandOver() : $tool, $step->tools);

        return $next($step->withModel($this->strongerModel)->withTools($tools));
    }

    /** @param  list<Message>  $messages */
    private function turnWasHandedOver(array $messages): bool
    {
        $lastUser = array_key_last(array_filter($messages, fn (Message $message) => $message->role === MessageRole::User));
        $turn = array_slice($messages, $lastUser ?? 0);

        foreach ($turn as $message) {
            if ($message instanceof AssistantMessage && $message->toolCalls->contains(fn (ToolCall $call) => $call->name === HandOver::NAME)) {
                return true;
            }
        }

        return false;
    }
}
