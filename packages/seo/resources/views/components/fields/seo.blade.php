@props(['field', 'name', 'value' => null, 'messages' => [], 'hint' => null])

{{--
 | Where an entry's SEO data is in Details: a pointer to the SEO tab, which
 | has the inputs, and whatever a refused save said about them, since Details
 | is where a form opens after one. The tab's script opens the tab itself
 | when this is all that was refused.
--}}
<x-mainstay::field-section :label="$field->label()">
    <p class="text-sm text-muted">Search and social settings are edited on the SEO tab.</p>
    @if ($messages !== [])
        <div data-seo-details-errors role="alert">
            @foreach ($messages as $message)
                <p class="pt-1.5 text-xs text-danger">{{ $message }}</p>
            @endforeach
        </div>
    @endif
</x-mainstay::field-section>
