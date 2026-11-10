@props(['id'])

{{--
 | A selected site block's settings, in a dialog over the page that the
 | block's toolbar opens: every field of the block, which the
 | server draws, and the blocks inside it, each selected on the page from
 | here. A form of its own beside the entry's rather than inside it, since a
 | form inside a form joins its inputs to the outer one and a Feature's
 | title would post as the page's. It never submits: canvas.ts fills it,
 | posts it and keeps it.
--}}
@push('dialogs')
    <dialog aria-labelledby="{{ $id }}-label" class="m-auto max-h-[calc(100dvh-3rem)] w-[calc(100%-3rem)] max-w-3xl rounded-control border border-border bg-canvas p-0 text-ink shadow-lg backdrop:bg-ink/30">
        <form id="{{ $id }}" data-canvas-sidebar aria-labelledby="{{ $id }}-label" class="flex max-h-[calc(100dvh-3rem-2px)] flex-col">
            <div class="flex shrink-0 items-center justify-between gap-3 border-b border-border px-4 py-3">
                <h2 id="{{ $id }}-label" data-sidebar-title class="text-sm font-medium">Block</h2>

                <button type="button" data-dialog-close aria-label="Close" class="rounded-control p-1 text-muted hover:bg-surface hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">
                    <x-mainstay::icon><path d="m4 4 8 8M12 4l-8 8" /></x-mainstay::icon>
                </button>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto">
                <div data-sidebar-messages role="alert" hidden class="mx-4 mt-3 space-y-1 rounded-control border border-danger/40 bg-surface px-3 py-2 text-sm text-danger"></div>
                <div data-sidebar-body></div>
                <section data-sidebar-inner aria-labelledby="{{ $id }}-inner" hidden class="space-y-2 border-t border-border px-4 py-3">
                    <h3 id="{{ $id }}-inner" class="text-xs font-medium text-muted">Blocks inside</h3>
                    <ul data-sidebar-list class="space-y-1"></ul>
                    <div data-sidebar-add class="flex flex-wrap gap-2"></div>
                    {{-- What canvas.ts fills each block inside, its icon and
                         its name, and each kind to add, from. --}}
                    <template data-sidebar-item><li><button type="button" data-inner-select class="flex w-full items-center gap-2 rounded-control px-2 py-1.5 text-left text-sm text-ink hover:bg-surface focus-visible:outline-2 focus-visible:outline-accent [&>span]:min-w-0 [&>span]:truncate [&>:not(span)]:shrink-0"></button></li></template>
                    <template data-sidebar-adder><x-mainstay::button type="button" variant="secondary" data-inner-add></x-mainstay::button></template>
                </section>
            </div>
        </form>
    </dialog>
@endpush
