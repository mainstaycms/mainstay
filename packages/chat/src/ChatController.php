<?php

namespace Mainstay\Chat;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Mainstay\Ai\AiConnectors;
use Mainstay\Auth\Gate;
use Mainstay\Navigation;
use Symfony\Component\HttpFoundation\Response;

/*
 | The chat screen and the two requests that stream: a message, and the
 | decision on what the model asked to write. The model's tools run as the
 | person signed in, who the admin's middleware has put on the request.
 */
class ChatController
{
    public const CONVERSATION_HEADER = 'X-Conversation-Id';

    public function index(Request $request): View
    {
        return view('chat::index', [
            'ready' => app(TextConnector::class)->ready(),
            'canConnect' => Navigation::uses(AiConnectors::class),
            'conversations' => Conversations::of(Gate::user()),
            'open' => is_string($open = $request->query('conversation')) ? $open : null,
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

        return $this->streamed($agent, $agent->stream($input['message']), $request);
    }

    public function decide(Request $request, string $conversation): Response
    {
        $input = $request->validate(['decisions' => ['required', 'array', 'min:1'], 'decisions.*' => ['boolean']]);

        $user = Gate::user();
        $agent = ChatAgent::make()->continue(Conversations::find($user, $conversation)->id, as: $user);

        try {
            return $this->streamed($agent, $agent->stream(Decisions::from(array_map(boolval(...), $input['decisions']))), $request);
        } catch (ApprovalMismatchException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    private function streamed(ChatAgent $agent, StreamableAgentResponse $stream, Request $request): Response
    {
        $response = $stream->toResponse($request);
        $response->headers->set(self::CONVERSATION_HEADER, $stream->conversationId ?? $agent->currentConversation());

        return $response;
    }
}
