<?php

namespace Mainstay\Chat;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Mainstay\Auth\User;

/*
 | The chat's conversations as one person has them. Every lookup starts from
 | the person: a conversation of someone else's is not found, not refused, so
 | that its id says nothing.
 */
class Conversations
{
    /** @return Collection<int, Conversation> newest first */
    public static function of(User $user): Collection
    {
        return self::ownedBy($user)->latest('updated_at')->get();
    }

    public static function find(User $user, string $id): Conversation
    {
        return self::ownedBy($user)->whereKey($id)->firstOrFail();
    }

    /** @return list<array{id: string, role: string, content: string|null, tool_calls: list<array<string, mixed>>}> */
    public static function transcript(Conversation $conversation): array
    {
        return $conversation->messages()->orderBy('id')->get()
            ->map(fn (ConversationMessage $message) => ['id' => $message->id, 'role' => $message->role, 'content' => $message->content, 'tool_calls' => $message->tool_calls])
            ->all();
    }

    /** @return Builder<Conversation> */
    private static function ownedBy(User $user): Builder
    {
        return Conversation::query()
            ->where('participant_type', Conversation::participantType($user))
            ->where('participant_id', Conversation::participantKey($user))
            ->whereHas('messages', fn (Builder $messages) => $messages->where('agent', ChatAgent::class));
    }
}
