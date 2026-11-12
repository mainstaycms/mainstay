@php
    $locales = \Mainstay\Facades\Mainstay::locales();
    $updates = \Mainstay\Navigation::media('update', $media);
    $document = $media->isDocument();
    $title ??= [];
    $description ??= [];

    if (! $document) {
        $preview = $media->sized(['preview' => \Mainstay\Media\Library::preview()])->url('preview');
        [$across, $down] = [old('focal.0', $media->focal[0]), old('focal.1', $media->focal[1])];
    }
@endphp

{{--
 | An image's focal point, set by clicking the image or typing the two
 | percentages, and its alt text in every language; a document's title and
 | description in every language beside its download. Either's visibility.
 | Its page's form, and the Content tab's image dialog's.
--}}
@if ($document)
    <div class="flex w-fit items-center gap-4 rounded-control border border-border bg-surface px-4 py-3">
        <span class="text-lg font-semibold uppercase text-muted">{{ \Mainstay\Media\Documents::extension($media->type) }}</span>
        <span class="text-sm">
            <span class="block font-medium">{{ $media->name }}</span>
            <span class="block text-muted">{{ $media->readableBytes() }}</span>
        </span>
        <a href="{{ $media->url() }}" download class="rounded-control border border-border px-3 py-1.5 text-sm hover:bg-canvas focus-visible:outline-2 focus-visible:outline-accent">Download</a>
    </div>

    <x-mainstay::field-group label="Document">
        @foreach ($locales as $code => $locale)
            <x-mainstay::field :label="'Title'.(count($locales) > 1 ? ' ('.strtoupper($code).')' : '')" :for="'title-'.$code" hint="The link text, where the site has none of its own.">
                <x-mainstay::input :id="'title-'.$code" :name="'title['.$code.']'" maxlength="255" :value="old('title.'.$code, $title[$code] ?? null)" :aria-describedby="'title-'.$code.'-hint'" />
            </x-mainstay::field>
        @endforeach
        @foreach ($locales as $code => $locale)
            <x-mainstay::field :label="'Description'.(count($locales) > 1 ? ' ('.strtoupper($code).')' : '')" :for="'description-'.$code">
                <x-mainstay::input :id="'description-'.$code" :name="'description['.$code.']'" maxlength="2000" :value="old('description.'.$code, $description[$code] ?? null)" />
            </x-mainstay::field>
        @endforeach
    </x-mainstay::field-group>
@else
    <div @if ($updates) data-focal @endif class="relative w-fit cursor-crosshair select-none">
        <img src="{{ $preview }}" alt="" data-focal-image class="block max-h-[28rem] max-w-full rounded-control border border-border">
        <span data-focal-marker aria-hidden="true" style="left: {{ $across }}%; top: {{ $down }}%" class="pointer-events-none absolute size-5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white bg-accent/70 shadow"></span>
    </div>

    <x-mainstay::field-group label="Image">
        <x-mainstay::field label="Focal point, across (%)" for="focal-across">
            <x-mainstay::input id="focal-across" type="number" name="focal[0]" min="0" max="100" step="1" :value="$across" data-focal-across />
        </x-mainstay::field>
        <x-mainstay::field label="Focal point, down (%)" for="focal-down">
            <x-mainstay::input id="focal-down" type="number" name="focal[1]" min="0" max="100" step="1" :value="$down" data-focal-down />
        </x-mainstay::field>
        @foreach ($locales as $code => $locale)
            <x-mainstay::field :label="'Alt text'.(count($locales) > 1 ? ' ('.strtoupper($code).')' : '')" :for="'alt-'.$code" hint="Empty marks the image decorative.">
                <x-mainstay::input :id="'alt-'.$code" :name="'alt['.$code.']'" maxlength="1000" :value="old('alt.'.$code, $alt[$code])" :aria-describedby="'alt-'.$code.'-hint'" />
            </x-mainstay::field>
        @endforeach
    </x-mainstay::field-group>
@endif

<x-mainstay::field-group label="Access">
    <x-mainstay::field label="Who may open it" for="visibility" hint="A restricted file is served to signed-in editors, and by links that expire. A public one may already sit in browser and CDN caches for a year.">
        <x-mainstay::select id="visibility" name="visibility" aria-describedby="visibility-hint">
            <option value="public" @selected(old('visibility', $media->visibility) === 'public')>Anyone</option>
            <option value="restricted" @selected(old('visibility', $media->visibility) === 'restricted')>Signed-in editors, or by a link that expires</option>
        </x-mainstay::select>
    </x-mainstay::field>
</x-mainstay::field-group>
