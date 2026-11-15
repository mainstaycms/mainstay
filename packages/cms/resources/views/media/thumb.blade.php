{{--
 | What a library item is drawn as: an image by its preview, a document as a
 | tile naming its type and size, and a lock on whichever is restricted.
 | `$image` the item, `$src` an image's preview, `$class` the tile's.
--}}
<span class="relative block">
    @if ($image->isDocument())
        <span class="grid aspect-square w-full place-content-center bg-surface text-center {{ $class ?? '' }}">
            <span class="text-lg font-semibold uppercase text-muted">{{ \Mainstay\Media\Documents::extension($image->type) }}</span>
            <span class="text-xs text-muted">{{ $image->readableBytes() }}</span>
        </span>
    @else
        <img src="{{ $src }}" alt="" class="aspect-square w-full bg-surface object-cover {{ $class ?? '' }}">
    @endif
    @if ($image->isRestricted())
        <span class="absolute left-1.5 top-1.5 inline-flex items-center gap-1 rounded-control border border-border bg-canvas px-1.5 py-0.5 text-xs text-muted">
            <svg viewBox="0 0 16 16" class="size-3" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="3" y="7" width="10" height="7" rx="1.5" /><path d="M5.5 7V5a2.5 2.5 0 0 1 5 0v2" /></svg>
            Restricted
        </span>
    @endif
</span>
