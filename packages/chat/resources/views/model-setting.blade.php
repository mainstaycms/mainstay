@php
    $connectors = app(\Mainstay\Ai\Connectors::class);
    $provider = $connectors->providerFor('text');
    $list = $provider === null ? \Mainstay\Ai\ModelList::unavailable('No provider is chosen for text on the Connectors screen: type the model id.') : app(\Mainstay\Ai\ModelCatalog::class)->for($provider, 'text');
@endphp

{{--
 | The chat's model settings, as a pick from the models the text provider
 | lists for its key; what posts, and is checked, is still the text.
--}}
<x-ai::model-picker :id="$id" :name="$name" :list="$list" :value="$value" :default="$provider === null ? null : $connectors->fallbackModel('text')" :invalid="(bool) $messages" :describedby="$describedby" :maxlength="\Mainstay\Chat\ChatModels::MAX_LENGTH" />
