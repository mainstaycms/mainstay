@props(['field', 'name', 'value' => null, 'messages' => [], 'hint' => null])

<x-mainstay::field label="Search description" :for="'field-'.$name">
    <x-mainstay::input :id="'field-'.$name" :name="$name" :value="$value" data-seo-meta />
</x-mainstay::field>
