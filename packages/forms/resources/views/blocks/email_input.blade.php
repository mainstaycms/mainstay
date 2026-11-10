<div>
    <label for="field-{{ $block->id }}">{{ $block->label }}</label>
    <input type="email" id="field-{{ $block->id }}" name="{{ $block->name }}" value="{{ $block->typed() }}" @required($block->required) @if ($block->error()) aria-invalid="true" @endif>
    @if ($block->error())<p role="alert">{{ $block->error() }}</p>@endif
</div>
