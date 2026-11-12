@props(['field', 'name', 'value' => null, 'messages' => [], 'hint' => null])

@php
    /* The file as read -- or, after a refused save, the id posted, read
       again. One the library no longer holds keeps its id and shows as
       missing until it is replaced. */
    $media = match (true) {
        $value instanceof \Mainstay\Content\Media => $value,
        is_string($value) && ctype_digit($value) => \Mainstay\Http\Form::readable(fn () => \Mainstay\Facades\Mainstay::media()->find((int) $value)) ?? new \Mainstay\Content\Media((int) $value),
        default => null,
    };
    $label = $media === null ? 'No file' : ($media->missing ? 'Missing' : ($media->title ?? $media->name));
@endphp

{{--
 | The file's name, its id in a hidden input, and Choose, Replace and Remove.
 | The same picker dialog as an image field's, asked for documents and for
 | the extensions this field takes; the behaviour module is shared.
--}}
<x-mainstay::field-section :label="$field->label()">
    <div data-image-field data-file-field data-picker="{{ route('mainstay.media', ['pick' => 1, 'kind' => 'document', 'accept' => implode(',', $field->extensions())]) }}" class="flex flex-wrap items-center gap-3">
        <input type="hidden" name="{{ $name }}" value="{{ $media?->id }}">
        <span data-file-name class="min-w-0 max-w-full truncate rounded-control border border-dashed border-border px-3 py-2 text-sm {{ $media === null || $media->missing ? 'text-muted' : '' }}">{{ $label }}</span>
        <div class="flex gap-2">
            <x-mainstay::button type="button" variant="secondary" data-image-choose>{{ $media ? 'Replace' : 'Choose' }}</x-mainstay::button>
            <x-mainstay::button type="button" variant="secondary" data-image-remove :hidden="$media === null">Remove</x-mainstay::button>
        </div>
    </div>
    <x-mainstay::messages :id="'field-'.$name.'-error'" :messages="$messages" />
</x-mainstay::field-section>

<x-mainstay::picker-dialog />
