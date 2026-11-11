{{--
 | The chat: the person's earlier conversations beside the one open, ruled by
 | hairlines on the page background like the admin's list screens. The
 | transcript, the streamed answer and the approval requests are drawn by
 | chat.js from the server's events; this draws what is there without it.
--}}
<x-mainstay::admin title="Chat" :breadcrumb="[['label' => 'Chat']]">
    <x-slot:actions>
        <a href="{{ route('mainstay.plugins.chat.index') }}" class="inline-flex items-center rounded-control bg-accent px-3 py-1.5 text-sm font-medium text-accent-ink hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">New chat</a>
    </x-slot:actions>

    @if (! $ready)
        <div class="h-full overflow-y-auto">
            <section aria-labelledby="chat-unconnected" class="px-5 py-5">
                <h2 id="chat-unconnected" class="mb-2 text-sm font-medium text-ink">No AI provider is connected</h2>
                <p class="text-sm text-muted">
                    The chat needs a provider with a key for text.
                    @if ($canConnect)
                        <a href="{{ route('mainstay.plugins.ai.index') }}" class="text-ink underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">Connect one on the Connectors screen.</a>
                    @else
                        Ask someone who manages the site's connectors to connect one.
                    @endif
                </p>
            </section>
        </div>
    @else
        <div
            class="flex h-full min-h-0"
            data-chat
            data-send="{{ route('mainstay.plugins.chat.send') }}"
            data-show="{{ route('mainstay.plugins.chat.show', '__id__') }}"
            data-decide="{{ route('mainstay.plugins.chat.decide', '__id__') }}"
            data-open="{{ $open }}"
        >
            <nav aria-labelledby="chat-conversations" class="hidden w-64 shrink-0 overflow-y-auto border-r border-border md:block">
                <h2 id="chat-conversations" class="px-5 py-4 text-sm font-medium text-ink">Conversations</h2>
                <ul data-conversations>
                    @foreach ($conversations as $conversation)
                        <li class="border-t border-border">
                            <a href="{{ route('mainstay.plugins.chat.index', ['conversation' => $conversation->id]) }}" data-conversation="{{ $conversation->id }}" class="block truncate px-5 py-3 text-sm hover:bg-surface focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-accent aria-[current=true]:bg-surface aria-[current=true]:font-medium" @if ($conversation->id === $open) aria-current="true" @endif>{{ $conversation->title }}</a>
                        </li>
                    @endforeach
                </ul>
                @if ($conversations->isEmpty())
                    <p class="px-5 pb-4 text-sm text-muted">Nothing yet.</p>
                @endif
            </nav>

            <section aria-label="Conversation" class="flex min-w-0 flex-1 flex-col">
                <div data-transcript role="log" aria-live="polite" class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4">
                    <p data-empty class="text-sm text-muted">Ask for a change to the site's content. Reads and drafts go ahead; anything that goes live or deletes waits for your approval here.</p>
                </div>
                <p data-error role="alert" hidden class="mx-5 mb-2 rounded-control border border-danger/40 bg-surface px-3 py-2 text-sm text-danger"></p>
                <form data-composer class="flex items-end gap-2 border-t border-border px-5 py-3">
                    <label for="chat-message" class="sr-only">Message</label>
                    <x-mainstay::textarea id="chat-message" name="message" rows="2" required maxlength="20000" placeholder="Message" />
                    <x-mainstay::button type="submit" class="shrink-0">Send</x-mainstay::button>
                </form>
            </section>
        </div>
    @endif
</x-mainstay::admin>
