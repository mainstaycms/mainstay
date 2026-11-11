@props(['label'])

{{--
 | An on/off setting: a real checkbox, so a plain form posts it and the
 | keyboard and screen readers get a checkbox's behaviour, drawn as a track
 | and knob. The input is only hidden from sight, never from the tab order,
 | and the track carries its focus ring.
--}}
<span class="relative inline-flex shrink-0">
    <input type="checkbox" role="switch" {{ $attributes->merge(['aria-label' => $label])->class('peer sr-only') }}>
    <span aria-hidden="true" class="relative h-5 w-9 rounded-full bg-border transition-colors after:absolute after:left-0.5 after:top-0.5 after:size-4 after:rounded-full after:bg-canvas after:shadow-sm after:transition-transform peer-checked:bg-accent peer-checked:after:translate-x-4 peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-accent peer-disabled:opacity-40"></span>
</span>
