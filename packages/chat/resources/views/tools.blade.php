{{--
 | The chat, in the admin's top bar on every screen: its button, and the
 | dialog the button opens, which chat.js fills. The conversations and whether
 | a provider is connected are fetched when the dialog first opens, so no
 | screen pays for them. Drawn by the admin's layout for whoever holds use_chat.
--}}
<button
    type="button"
    data-dialog-open="chat-dialog"
    data-chat-open
    title="{{ $label }} (Ctrl+.)"
    class="flex h-6 items-center whitespace-nowrap rounded-control px-1.5 text-xs text-muted hover:bg-canvas hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
>
    <x-mainstay::icon class="size-4 sm:hidden"><path d="M2.75 2.75h10.5a1 1 0 0 1 1 1v6.5a1 1 0 0 1-1 1H8l-3 2.5v-2.5H2.75a1 1 0 0 1-1-1v-6.5a1 1 0 0 1 1-1Z" /></x-mainstay::icon>
    <span class="max-sm:sr-only">{{ $label }}</span>
</button>

@push('dialogs')
    <x-mainstay::dialog id="chat-dialog" :label="$label" wide plain class="max-w-5xl! rounded-none!">
        <div
            class="relative flex h-[min(40rem,calc(100dvh-9rem))] min-h-0 border-t border-border"
            data-chat
            data-conversations-url="{{ route('mainstay.plugins.chat.conversations') }}"
            data-send="{{ route('mainstay.plugins.chat.send') }}"
            data-show="{{ route('mainstay.plugins.chat.show', '__id__') }}"
            data-decide="{{ route('mainstay.plugins.chat.decide', '__id__') }}"
        >
            <section data-unconnected hidden class="h-full w-full overflow-y-auto px-5 py-5">
                <h3 class="mb-2 text-sm font-medium text-ink">No AI provider is connected</h3>
                <p class="text-sm text-muted">
                    The chat needs a provider with a key for text.
                    @if (\Mainstay\Navigation::uses(\Mainstay\Ai\AiConnectors::class))
                        <a href="{{ route('mainstay.plugins.ai.index') }}" class="text-ink underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">Connect one on the Connectors screen.</a>
                    @else
                        Ask someone who manages the site's connectors to connect one.
                    @endif
                </p>
            </section>

            <nav data-list aria-label="Conversations" class="absolute inset-y-0 left-0 z-10 hidden w-64 overflow-y-auto border-r border-border bg-canvas data-[open]:block md:static md:block md:shrink-0">
                <ul data-conversations></ul>
                <p data-no-conversations hidden class="px-5 py-3 text-sm text-muted">Nothing yet.</p>
            </nav>

            <section data-conversation-pane aria-label="Conversation" class="flex min-w-0 flex-1 flex-col">
                <div class="flex items-center gap-2 border-b border-border px-5 py-2">
                    <button type="button" data-toggle-list aria-expanded="false" class="rounded-control border border-border bg-surface px-2.5 py-1 text-xs font-medium hover:bg-canvas focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent md:hidden">Conversations</button>
                    <button type="button" data-new-chat class="ml-auto rounded-control border border-border bg-surface px-2.5 py-1 text-xs font-medium hover:bg-canvas focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent disabled:opacity-50">New chat</button>
                </div>
                <div data-transcript role="log" aria-live="polite" class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4">
                    <p data-empty class="text-sm text-muted">Ask for a change to the site's content. Reads and drafts go ahead; anything that goes live or deletes waits for your approval here.</p>
                </div>
                <p data-error role="alert" hidden class="mx-5 mb-2 rounded-control border border-danger/40 bg-surface px-3 py-2 text-sm text-danger"></p>
                <form data-composer class="flex shrink-0 items-end gap-2 border-t border-border px-5 py-3">
                    <label for="chat-message" class="sr-only">Message</label>
                    <x-mainstay::textarea id="chat-message" name="message" rows="2" required maxlength="20000" placeholder="Message" />
                    <x-mainstay::button type="submit" class="shrink-0">Send</x-mainstay::button>
                </form>
            </section>
        </div>
    </x-mainstay::dialog>
@endpush
