@php
    $uploads = \Mainstay\Navigation::uploads();
    $kind = $filter['kind'] ?? null;
    $pager = fn (int $page) => '?'.http_build_query(array_filter(['kind' => $kind, 'page' => $page]));
@endphp

{{--
 | The media library, or its trash: every image by its preview and document by a tile, newest
 | first. A file dropped on the grid opens the upload with it.
--}}
<x-mainstay::admin
    :title="$trashed ? 'Media trash' : 'Media'"
    :breadcrumb="$trashed ? [['label' => 'Media', 'href' => route('mainstay.media')], ['label' => 'Trash']] : [['label' => 'Media']]"
>
    <x-slot:actions>
        @unless ($trashed)
            <a href="{{ route('mainstay.media.trash') }}" class="rounded-control px-2 py-1 text-sm text-muted hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">Trash</a>
            @if ($uploads)
                <x-mainstay::button type="button" data-dialog-open="upload">Upload</x-mainstay::button>
            @endif
        @endunless
    </x-slot:actions>

    <div class="flex h-full min-h-0 flex-col">
        @unless ($trashed)
            <nav aria-label="Kind" class="flex gap-1 border-b border-border px-5 py-2 text-sm">
                @foreach (['' => 'All', 'image' => 'Images', 'document' => 'Documents'] as $value => $label)
                    <a href="{{ route('mainstay.media', array_filter(['kind' => $value])) }}" @if (($kind ?? '') === $value) aria-current="page" @endif class="rounded-control px-2 py-1 {{ ($kind ?? '') === $value ? 'bg-surface font-medium text-ink' : 'text-muted hover:text-ink' }}">{{ $label }}</a>
                @endforeach
            </nav>
        @endunless
        <div @if ($uploads && ! $trashed) data-media-drop="upload" @endif class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
            <x-mainstay::notices :messages="$errors->all()" class="pb-4" />

            @if ($images->isEmpty())
                <div class="grid h-full place-content-center text-center">
                    <p class="text-sm font-medium">{{ $trashed ? 'The trash is empty' : 'No files yet' }}</p>
                    <p class="pt-1 text-sm text-muted">{{ $trashed ? 'What you move to the trash waits here until you restore it or delete it for good.' : 'Upload an image or a document, or drop a file here.' }}</p>
                </div>
            @else
                @include('mainstay::media.grid', ['mode' => $trashed ? 'trash' : 'link'])
            @endif
        </div>

        @if ($images->lastPage() > 1)
            <x-mainstay::pager :start="$images->firstItem() - 1" :shown="$images->count()" :total="$images->total()" :page="$images->currentPage()" :page-count="$images->lastPage()" :href="$pager" />
        @endif
    </div>

    @if ($uploads && ! $trashed)
        <x-mainstay::dialog id="upload" label="Upload a file">
            @include('mainstay::media.upload')
        </x-mainstay::dialog>
    @endif
</x-mainstay::admin>
