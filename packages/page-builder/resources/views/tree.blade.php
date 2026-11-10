{{-- On the Content tab, in the rail's place: every block of the content, the
     ones inside another under it, each by its icon and its name, the current
     one marked, each selected on the page from here. canvas.ts fills it from
     the template and swaps it for the rail. --}}
<aside aria-labelledby="canvas-tree-label" data-canvas-tree hidden class="min-h-0 w-72 shrink-0 overflow-y-auto border-l border-border bg-surface px-4 py-3">
    <h2 id="canvas-tree-label" class="pb-1.5 text-xs font-medium text-muted">Blocks</h2>
    <ul data-tree-list class="-mx-2 [&_ul]:ml-3 [&_ul]:border-l [&_ul]:border-border [&_ul]:pl-1"></ul>
    <template data-tree-item><li><button type="button" data-tree-select class="flex w-full items-center gap-2 rounded-control px-2 py-1.5 text-left text-sm text-ink hover:bg-canvas aria-[current=true]:bg-accent aria-[current=true]:text-accent-ink focus-visible:outline-2 focus-visible:outline-accent [&>span]:min-w-0 [&>span]:truncate [&>:not(span)]:shrink-0"></button></li></template>
</aside>
