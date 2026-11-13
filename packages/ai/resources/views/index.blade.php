@php
    $labels = array_column($providers, 'label', 'name');
    $keys = [
        'config' => "Key set in the site's config",
        'saved' => 'Key saved here, encrypted',
        'unreadable' => 'Key saved here under another APP_KEY, so unreadable: save it again or remove it',
        'none' => 'No key',
    ];
@endphp
@php($modelHint = 'Use a model id that this provider offers. Blank uses its own default.')

{{--
 | The AI providers the site can talk to, as WordPress's Settings >
 | Connectors has them: a cell per provider -- what it does, where its key
 | comes from, a field to save one, Test and Remove -- then which one each
 | kind of work goes to, and the model it asks there. Ruled with hairlines
 | like the admin's other screens. A key is never shown again once saved, and
 | one the site's config sets is changed there.
--}}
<x-mainstay::admin title="Connectors" :breadcrumb="[['label' => 'Connectors']]">
    <x-slot:actions>
        <x-mainstay::button type="submit" form="connectors-defaults">Save defaults</x-mainstay::button>
    </x-slot:actions>

    <div class="h-full overflow-y-auto">
        <div class="px-5 pt-5 empty:hidden"><x-mainstay::notices :messages="$errors->all()" /></div>

        <section aria-labelledby="providers-heading">
            <h2 id="providers-heading" class="border-b border-border px-5 py-2.5 text-xs font-medium text-muted">Providers</h2>
            <p class="border-b border-border px-5 py-3 text-sm text-muted">A key set in the site's config, from its environment, wins over one saved here.</p>

            <ul class="grid [&>li]:border-b [&>li]:border-border sm:grid-cols-2 sm:[&>li:nth-child(odd)]:border-r">
                @foreach ($providers as $provider)
                    <li class="space-y-3 px-5 py-4">
                        <div>
                            <h3 class="text-sm font-medium">{{ $provider['label'] }}</h3>
                            <p class="text-xs text-muted">{{ implode(' · ', array_map(fn (string $use) => $uses[$use], $provider['uses'])) ?: 'Nothing Mainstay knows of' }}</p>
                        </div>
                        <p class="text-sm">{{ $keys[$provider['key'] ?? 'none'] }}</p>
                        @if ($provider['broken'])
                            <p role="alert" class="text-sm text-danger">Its config makes no provider: {{ $provider['broken'] }}</p>
                        @endif

                        @if ($provider['key'] !== 'config')
                            <form method="post" action="{{ route('mainstay.plugins.ai.save', $provider['name']) }}" class="flex gap-2">
                                @csrf
                                <label for="key-{{ $provider['name'] }}" class="sr-only">{{ $provider['label'] }}'s key</label>
                                <x-mainstay::input :id="'key-'.$provider['name']" name="key" type="password" autocomplete="new-password" required :placeholder="$provider['key'] === 'saved' ? 'A new key' : 'Key'" />
                                <x-mainstay::button type="submit" variant="secondary">Save</x-mainstay::button>
                            </form>
                        @endif

                        <div class="flex gap-2">
                            <form method="post" action="{{ route('mainstay.plugins.ai.test', $provider['name']) }}">
                                @csrf
                                <x-mainstay::button type="submit" variant="secondary">Test</x-mainstay::button>
                            </form>
                            @if (in_array($provider['key'], ['saved', 'unreadable'], true))
                                <x-mainstay::button type="button" variant="secondary" data-dialog-open="forget-{{ $provider['name'] }}">Remove key</x-mainstay::button>
                                <x-mainstay::dialog :id="'forget-'.$provider['name']" :label="'Remove '.$provider['label'].'\'s key?'">
                                    <form method="post" action="{{ route('mainstay.plugins.ai.forget', $provider['name']) }}" class="space-y-4">
                                        @csrf
                                        <p>Whatever the site asks {{ $provider['label'] }} stops working until a key is saved again. Mainstay keeps no copy.</p>
                                        <div class="flex justify-end gap-2">
                                            <x-mainstay::button type="button" variant="secondary" data-dialog-close>Keep it</x-mainstay::button>
                                            <x-mainstay::button type="submit" variant="danger">Remove</x-mainstay::button>
                                        </div>
                                    </form>
                                </x-mainstay::dialog>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>

        <form id="refresh-models" method="post" action="{{ route('mainstay.plugins.ai.models.refresh') }}">@csrf</form>

        <form id="connectors-defaults" method="post" action="{{ route('mainstay.plugins.ai.defaults') }}">
            @csrf
            <section aria-labelledby="uses-heading">
                <h2 id="uses-heading" class="border-b border-border px-5 py-2.5 text-xs font-medium text-muted">Used for</h2>
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-3">
                    <p class="text-sm text-muted">Each kind of work goes to one provider and one of its models, picked from what the provider lists for its key. Change the model along with the provider.</p>
                    <x-mainstay::button type="submit" variant="secondary" form="refresh-models">Refresh models</x-mainstay::button>
                </div>

                <ul class="grid [&>li]:border-b [&>li]:border-border sm:grid-cols-2 sm:[&>li:nth-child(odd)]:border-r">
                    @foreach ($uses as $use => $label)
                        @php($able = array_values(array_filter($providers, fn (array $provider) => in_array($use, $provider['uses'], true))))
                        @continue($able === [])
                        @php($shownProvider = (string) $selections[$use]['provider'])
                        <li class="[&>*:last-child]:border-b-0">
                            <x-mainstay::field :label="$label" :for="'default-'.$use">
                                <x-mainstay::select :id="'default-'.$use" :name="'defaults['.$use.']'">
                                    <option value="">As the site's config says: {{ $labels[$defaults[$use]['provider'] ?? ''] ?? 'none' }}</option>
                                    @foreach ($able as $provider)
                                        <option value="{{ $provider['name'] }}" @selected($shownProvider === $provider['name'])>{{ $provider['label'] }}{{ in_array($provider['key'], [null, 'unreadable'], true) ? ' (no key)' : '' }}</option>
                                    @endforeach
                                </x-mainstay::select>
                            </x-mainstay::field>
                            <x-mainstay::field :label="$label.' model'" :for="'model-'.$use" :hint="$modelHint.($fallbacks[$use] ? ' Default now: '.$fallbacks[$use].', for the provider saved; this does not change until you save.' : '')">
                                <x-ai::model-picker :id="'model-'.$use" :name="'models['.$use.']'" :list="$lists[$use]" :value="$selections[$use]['model']" :default="$shownProvider === '' ? ($selections[$use]['pin']['default'] ?? $fallbacks[$use]) : app(\Mainstay\Ai\Connectors::class)->fallbackModel($use, $shownProvider)" :disabled="$shownProvider === '' && $selections[$use]['pin'] === null" :pin="$shownProvider === '' ? $selections[$use]['pin'] : null" :pin-available="$selections[$use]['pin']" :blank-default="$selections[$use]['pin']['default'] ?? $fallbacks[$use]" :hint-base="$modelHint" :use="$use" :provider-select="'default-'.$use" :invalid="$errors->has('models.'.$use)" :describedby="'model-'.$use.'-hint'" />
                            </x-mainstay::field>
                        </li>
                    @endforeach
                </ul>
            </section>
        </form>
    </div>
</x-mainstay::admin>
