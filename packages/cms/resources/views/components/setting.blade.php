@props(['setting', 'name', 'value' => null, 'messages' => []])

@php($id = 'setting-'.str_replace(['[', ']'], ['-', ''], $name))

{{--
 | One setting of a plugin, drawn by its type: a flag as a switch, the rest as
 | the control a field of that kind is. Its description is the hint under it.
 | A box left off posts nothing, so the hidden 0 before a switch is what off
 | says.
--}}
@if ($setting->type === 'boolean')
    <div class="border-b border-border px-4 py-3.5">
        <label for="{{ $id }}" class="flex cursor-pointer items-start justify-between gap-3">
            <span class="min-w-0">
                <span class="block text-sm font-medium text-ink">{{ $setting->label }}</span>
                @if ($setting->description !== '')
                    <span id="{{ $id }}-hint" class="mt-1 block text-xs text-muted">{{ $setting->description }}</span>
                @endif
            </span>
            <input type="hidden" name="{{ $name }}" value="0">
            <x-mainstay::switch :id="$id" :name="$name" value="1" :label="$setting->label" :checked="filter_var($value, FILTER_VALIDATE_BOOL)" :aria-invalid="$messages ? 'true' : null" :aria-describedby="$messages ? $id.'-error' : ($setting->description !== '' ? $id.'-hint' : null)" />
        </label>
        <x-mainstay::messages :id="$id.'-error'" :messages="$messages" />
    </div>
@else
    <x-mainstay::field :label="$setting->label" :for="$id" :hint="$setting->description ?: null">
        @php($describedby = $messages ? $id.'-error' : ($setting->description !== '' ? $id.'-hint' : null))
        @if ($setting->type === 'textarea')
            <x-mainstay::textarea :id="$id" :name="$name" rows="8" :placeholder="$setting->placeholder" :aria-invalid="$messages ? 'true' : null" :aria-describedby="$describedby">{{ $value }}</x-mainstay::textarea>
        @elseif ($setting->type === 'select')
            <x-mainstay::select :id="$id" :name="$name" :aria-invalid="$messages ? 'true' : null" :aria-describedby="$describedby">
                @foreach ($setting->options as $option => $label)
                    <option value="{{ $option }}" @selected((string) $value === (string) $option)>{{ $label }}</option>
                @endforeach
            </x-mainstay::select>
        @else
            <x-mainstay::input :id="$id" :name="$name" :type="$setting->type === 'number' ? 'number' : 'text'" :step="$setting->type === 'number' ? 'any' : null" :placeholder="$setting->placeholder" :value="$value" :aria-invalid="$messages ? 'true' : null" :aria-describedby="$describedby" />
        @endif
        <x-mainstay::messages :id="$id.'-error'" :messages="$messages" />
    </x-mainstay::field>
@endif
