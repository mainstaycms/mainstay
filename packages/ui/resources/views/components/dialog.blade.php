@props(['id', 'label', 'wide' => false, 'plain' => false])

{{--
 | A question asked over the page, on the platform's own <dialog>: shown modal
 | by a control naming it in `data-dialog-open`, closed by its button, Escape
 | and anything marked `data-dialog-close`. Focus is trapped and returned by
 | the browser, which is the part a hand-built modal gets wrong.
 |
 | `plain` drops the title bar and the body's padding, for a dialog whose
 | content is ruled edge to edge like the dashboard: the title is a heading
 | in the panel, the close button floats at its corner, and the content
 | draws its own spacing.
 |
 | Drawn outside any other form: a form inside a form is dropped by the parser
 | and its inputs join the outer one.
--}}
<dialog
    id="{{ $id }}"
    aria-labelledby="{{ $id }}-label"
    {{ $attributes->class(($wide ? 'max-w-3xl' : 'max-w-md').' m-auto w-full rounded-control border border-border bg-canvas p-0 text-ink shadow-lg backdrop:bg-ink/30') }}
>
    @php
        $closeButton = 'rounded-control p-1 text-muted hover:bg-surface hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';
    @endphp

    @if ($plain)
        <div class="relative px-5 py-4">
            <h2 id="{{ $id }}-label" class="pr-8 text-sm font-medium text-ink">{{ $label }}</h2>

            <button type="button" data-dialog-close aria-label="Close" class="absolute right-3 top-3 {{ $closeButton }}">
                <x-mainstay::icon><path d="m4 4 8 8M12 4l-8 8" /></x-mainstay::icon>
            </button>
        </div>

        <div class="text-sm">{{ $slot }}</div>
    @else
        <div class="flex items-center justify-between gap-3 border-b border-border px-4 py-3">
            <h2 id="{{ $id }}-label" class="text-sm font-medium">{{ $label }}</h2>

            <button type="button" data-dialog-close aria-label="Close" class="{{ $closeButton }}">
                <x-mainstay::icon><path d="m4 4 8 8M12 4l-8 8" /></x-mainstay::icon>
            </button>
        </div>

        <div class="px-4 py-4 text-sm">{{ $slot }}</div>
    @endif
</dialog>
