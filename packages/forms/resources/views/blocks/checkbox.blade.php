<div>
    <input type="hidden" name="{{ $block->name }}" value="0">
    <input type="checkbox" id="field-{{ $block->id }}" name="{{ $block->name }}" value="1" @checked(filter_var($block->typed(), FILTER_VALIDATE_BOOLEAN)) @required($block->required) @if ($block->error()) aria-invalid="true" @endif>
    <label for="field-{{ $block->id }}">{{ $block->label }}</label>
    @if ($block->error())<p role="alert">{{ $block->error() }}</p>@endif
</div>
