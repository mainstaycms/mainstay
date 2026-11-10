<div>
    <label for="field-{{ $block->id }}">{{ $block->label }}</label>
    <input type="text" id="field-{{ $block->id }}" name="{{ $block->name }}" value="{{ $block->typed() }}" @required($block->required) @if ($block->maxLength ?? null) maxlength="{{ $block->maxLength }}" @endif @if ($block->error()) aria-invalid="true" @endif>
    @if ($block->error())<p role="alert">{{ $block->error() }}</p>@endif
</div>
