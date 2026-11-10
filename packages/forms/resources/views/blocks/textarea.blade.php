<div>
    <label for="field-{{ $block->id }}">{{ $block->label }}</label>
    <textarea id="field-{{ $block->id }}" name="{{ $block->name }}" @required($block->required) @if ($block->maxLength ?? null) maxlength="{{ $block->maxLength }}" @endif @if ($block->error()) aria-invalid="true" @endif>{{ $block->typed() }}</textarea>
    @if ($block->error())<p role="alert">{{ $block->error() }}</p>@endif
</div>
