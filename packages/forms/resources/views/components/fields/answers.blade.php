@props(['field', 'name', 'value' => null, 'messages' => [], 'hint' => null])

<x-mainstay::field :label="$field->label()" :for="'field-'.$name">
    <dl id="field-{{ $name }}">
        @foreach ($value ?? [] as $answer)
            <dt>{{ $answer['label'] }}</dt>
            <dd>{{ is_bool($answer['value']) ? ($answer['value'] ? 'Yes' : 'No') : $answer['value'] }}</dd>
        @endforeach
    </dl>
</x-mainstay::field>
