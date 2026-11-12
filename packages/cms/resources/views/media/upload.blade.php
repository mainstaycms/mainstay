{{--
 | An upload: the file, and for an image its alt text in every language, each
 | present as the library asks -- left empty it marks the image decorative --
 | and for a document a title, and who may be served it. `$query` is what the
 | picker asks the grid for, so the upload answers with the same grid.
--}}
@php
    $kind = $query['kind'] ?? null;
    $extensions = ($query['accept'] ?? null) ? explode(',', $query['accept']) : \Mainstay\Media\Documents::allowed();
    $accept = implode(',', [
        ...($kind === 'document' ? [] : ['image/jpeg', 'image/png', 'image/gif', 'image/webp']),
        ...($kind === 'image' ? [] : array_map(fn (string $extension) => ".{$extension}", $extensions)),
    ]);
@endphp
<form method="post" action="{{ route('mainstay.media.store', $pick ?? false ? $query : []) }}" enctype="multipart/form-data" data-media-upload class="space-y-3">
    @csrf
    <div>
        <label for="media-file{{ $suffix ?? '' }}" class="block pb-1.5 text-xs font-medium text-muted">{{ $kind === 'image' ? 'Image' : ($kind === 'document' ? 'File' : 'Image or file') }}</label>
        <input id="media-file{{ $suffix ?? '' }}" type="file" name="file" accept="{{ $accept }}" required class="block w-full text-sm file:mr-3 file:rounded-control file:border file:border-border file:bg-surface file:px-3 file:py-1.5 file:text-sm">
    </div>
    @if ($kind !== 'document')
        @foreach ($locales as $code => $locale)
            <div>
                <label for="media-alt-{{ $code }}{{ $suffix ?? '' }}" class="block pb-1.5 text-xs font-medium text-muted">Alt text{{ count($locales) > 1 ? ' ('.strtoupper($code).')' : '' }}</label>
                <x-mainstay::input :id="'media-alt-'.$code.($suffix ?? '')" :name="'alt['.$code.']'" maxlength="1000" />
            </div>
        @endforeach
        <p class="text-xs text-muted">Describe what an image shows for someone who cannot see it. Leave it empty for an image that is only decoration.</p>
    @endif
    @if ($kind !== 'image')
        @foreach ($locales as $code => $locale)
            <div>
                <label for="media-title-{{ $code }}{{ $suffix ?? '' }}" class="block pb-1.5 text-xs font-medium text-muted">Title of a document{{ count($locales) > 1 ? ' ('.strtoupper($code).')' : '' }}</label>
                <x-mainstay::input :id="'media-title-'.$code.($suffix ?? '')" :name="'title['.$code.']'" maxlength="255" />
            </div>
        @endforeach
    @endif
    <div>
        <label for="media-visibility{{ $suffix ?? '' }}" class="block pb-1.5 text-xs font-medium text-muted">Who may open it</label>
        <x-mainstay::select id="media-visibility{{ $suffix ?? '' }}" name="visibility">
            <option value="public">Anyone</option>
            <option value="restricted">Signed-in editors, or by a link that expires</option>
        </x-mainstay::select>
    </div>
    <div class="flex justify-end">
        <x-mainstay::button type="submit">Upload</x-mainstay::button>
    </div>
</form>
