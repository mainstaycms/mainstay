{{-- Content's own controls, for the whole document, at the end of the tabs' bar while it is open. --}}
<div id="canvas-header" role="toolbar" aria-label="Content" hidden class="absolute inset-y-0 right-4 flex items-center gap-0.5">
    @php($tool = 'rounded-control px-2 py-1 text-sm text-muted hover:bg-surface hover:text-ink disabled:opacity-40 focus-visible:outline-2 focus-visible:outline-accent')
    <button type="button" data-command="undo" class="{{ $tool }}">Undo</button>
    <button type="button" data-command="redo" class="{{ $tool }}">Redo</button>
    <span aria-hidden="true" class="mx-1 h-5 w-px bg-border"></span>
    <button type="button" data-command="add" aria-haspopup="dialog" aria-controls="canvas-inserter" class="{{ $tool }}">Add block</button>
</div>
