<?php

namespace Mainstay\Chat;

use Generator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\Error;
use Mainstay\Ai\AiConnectors;
use Mainstay\Auth\Gate;
use Mainstay\Auth\User;
use Mainstay\Navigation;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/*
 | The chat's conversations and the two requests that stream: a message, and the
 | decision on what the model asked to write. The model's tools run as the
 | person signed in, who the admin's middleware has put on the request.
 */
class ChatController
{
    public const CONVERSATION_HEADER = 'X-Conversation-Id';

    public function conversations(): JsonResponse
    {
        return response()->json([
            'ready' => app(TextConnector::class)->ready(),
            'conversations' => Conversations::of(Gate::user())->map(fn (Conversation $conversation) => $conversation->only(['id', 'title']))->values(),
        ]);
    }

    public function show(string $conversation): JsonResponse
    {
        return response()->json(['messages' => Conversations::transcript(Conversations::find(Gate::user(), $conversation))]);
    }

    public function send(Request $request): Response
    {
        $input = $request->validate(['message' => ['required', 'string', 'max:20000'], 'conversation' => ['nullable', 'uuid']]);

        if (! app(TextConnector::class)->ready()) {
            return response()->json(['message' => 'No AI provider is connected for text.'], 409);
        }

        $user = Gate::user();
        $conversation = isset($input['conversation']) ? Conversations::find($user, $input['conversation']) : null;
        $agent = ChatAgent::make()->continueOrStart($conversation?->id, $user);

        return $this->streamed($agent, $agent->stream($input['message']));
    }

    public function decide(Request $request, string $conversation): Response
    {
        $input = $request->validate(['decisions' => ['required', 'array', 'min:1'], 'decisions.*' => ['boolean']]);

        $user = Gate::user();
        $agent = ChatAgent::make()->continue(Conversations::find($user, $conversation)->id, as: $user);

        try {
            return $this->streamed($agent, $agent->stream(Decisions::from(array_map(boolval(...), $input['decisions']))));
        } catch (ApprovalMismatchException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    // Not laravel/ai's toResponse(): a provider's failure leaves its callback after the headers are sent, where Laravel's handler can only break the response. This one ends it with an error event.
    private function streamed(ChatAgent $agent, StreamableAgentResponse $stream): Response
    {
        $conversation = $stream->conversationId ?? $agent->currentConversation();
        $response = response()->stream(fn () => yield from $this->events($stream, $conversation, Gate::user()), headers: [
            // Without these a proxy may buffer or transcode the body, holding every event back until the run ends.
            'Cache-Control' => 'no-cache, no-transform',
            'Content-Type' => 'text/event-stream',
            'X-Accel-Buffering' => 'no',
        ]);
        $response->headers->set(self::CONVERSATION_HEADER, $conversation);

        return $response;
    }

    /** @return Generator<int, string> */
    private function events(StreamableAgentResponse $stream, ?string $conversation, User $user): Generator
    {
        try {
            foreach ($stream as $event) {
                // The provider's own words for it, which the failure that follows replaces with ours.
                if (! $event instanceof Error) {
                    yield "data: {$event}\n\n";
                }
            }
        } catch (Throwable $failure) {
            report(ProviderFailed::from($failure, $conversation, $user->getKey()));

            // A turn that died before its first step is not stored, so the page must not keep the id the header gave it.
            yield 'data: '.json_encode([
                'type' => 'error',
                'message' => ProviderFailure::describe($failure, Navigation::uses(AiConnectors::class)),
                'conversation_kept' => $conversation !== null && Conversations::has($user, $conversation),
            ])."\n\n";
        }

        yield "data: [DONE]\n\n";
    }
}
