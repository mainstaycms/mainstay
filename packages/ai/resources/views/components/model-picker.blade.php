@props(['id', 'name', 'list', 'value' => null, 'default' => null, 'use' => null, 'providerSelect' => null, 'disabled' => false, 'pin' => null, 'pinAvailable' => null, 'blankDefault' => null, 'hintBase' => null, 'invalid' => false, 'describedby' => null, 'maxlength' => 255])

@php
    $saved = (string) $value;
    $options = $saved !== '' && ! in_array($saved, $list->models, true) ? [$saved, ...$list->models] : $list->models;
    $note = $disabled ? 'Choose a provider to pick a model.' : $list->note;
    $pinLabel = $pin['label'] ?? $pinAvailable['label'] ?? null;
@endphp

{{--
 | A model field that offers the models its provider lists. The text input is
 | the field: it is what posts and what is validated, and without script it is
 | all there is. ai.js shows the list beside it, copies a pick into the input,
 | and reveals the input again for "Custom...". A model saved but not in the
 | list stays in it, so a custom id is not lost. With a provider select named
 | in `providerSelect`, ai.js fetches the new provider's models when it changes.
 |
 | A use left to the site's config (`pin`, the provider the config names, when
 | it has a key) offers that provider's models, and saving a model sets that
 | provider for the use: the line under the field says so, and ai.js switches
 | the provider select to it as a model is picked. With no provider to offer,
 | both controls are `disabled` until one is chosen.
--}}
<div data-model-picker data-custom-label="Custom..." data-blank-default="{{ $blankDefault }}" data-hint-base="{{ $hintBase }}" data-hint="{{ $describedby }}" data-pin-provider="{{ $pinAvailable['name'] ?? '' }}" @if ($providerSelect) data-provider-select="{{ $providerSelect }}" data-endpoint="{{ route('mainstay.plugins.ai.models') }}" data-use="{{ $use }}" @endif>
    <x-mainstay::select :id="$id.'-list'" hidden :disabled="$disabled" data-model-list :data-pickable="$list->isPickable() ? 'true' : 'false'" aria-label="Models offered" :aria-describedby="$describedby">
        <option value="">{{ $default ? 'Default: '.$default : 'Default' }}</option>
        @foreach ($options as $model)
            <option value="{{ $model }}" @selected($saved === $model)>{{ $model }}</option>
        @endforeach
        <option value=" custom">Custom...</option>
    </x-mainstay::select>
    <x-mainstay::input :id="$id" :name="$name" type="text" :maxlength="$maxlength" autocomplete="off" spellcheck="false" :disabled="$disabled" :value="$value" :placeholder="$default ?? 'Model id'" :aria-invalid="$invalid ? 'true' : null" :aria-describedby="$describedby" class="mt-1" />
    <p data-model-note role="status" class="pt-1 text-xs text-muted empty:hidden">{{ $note }}</p>
    @if ($pinLabel)
        <p data-model-pin @if ($pin === null) hidden @endif class="pt-1 text-xs text-muted">Saving a model also sets the provider to {{ $pinLabel }} for this use.</p>
    @endif
</div>
