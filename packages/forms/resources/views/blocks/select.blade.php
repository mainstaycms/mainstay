<div>
    <label for="field-{{ $block->id }}">{{ $block->label }}</label>
    <select id="field-{{ $block->id }}" name="{{ $block->name }}" @required($block->required) @if ($block->error()) aria-invalid="true" @endif>
        <option value=""></option>
        @foreach ($block->choices() as $choice)
            <option value="{{ $choice }}" @selected($block->typed() === $choice)>{{ $choice }}</option>
        @endforeach
    </select>
    @if ($block->error())<p role="alert">{{ $block->error() }}</p>@endif
</div>
