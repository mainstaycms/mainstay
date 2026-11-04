@php
    $labels = array_column($providers, 'label', 'name');
    $keys = [
        'config' => "Key set in the site's config",
        'saved' => 'Key saved here, encrypted',
        'unreadable' => 'Key saved here under another APP_KEY, so unreadable: save it again or remove it',
        'none' => 'No key',
    ];
@endphp

{{--
 | The AI providers the site can talk to, as WordPress's Settings >
 | Connectors has them: a card per provider -- what it does, where its key
 | comes from, a field to save one, Test and Remove -- then which one each
 | kind of work goes to. A key is never shown again once saved, and one the
 | site's config sets is changed there.
--}}
<x-mainstay::admin title="Connectors" :breadcrumb="[['label' => 'Connectors']]">
    <x-slot:actions>
        <x-mainstay::button type="submit" form="connectors-defaults">Save defaults</x-mainstay::button>
    </x-slot:actions>

    <div class="h-full overflow-y-auto">
        <div class="mx-auto max-w-4xl space-y-6 px-6 py-8">
            <x-mainstay::notices :messages="$errors->all()" />

            <section aria-labelledby="providers-heading" class="space-y-3">
                <h2 id="providers-heading" class="text-xs font-medium text-muted">Providers</h2>
                <p class="text-sm text-muted">A key set in the site's config, from its environment, wins over one saved here.</p>

                <ul class="grid gap-4 sm:grid-cols-2">
                    @foreach ($providers as $provider)
                        <li class="space-y-3 rounded-control border border-border bg-surface p-4">
                            <div>
                                <h3 class="text-sm font-medium">{{ $provider['label'] }}</h3>
                                <p class="text-xs text-muted">{{ implode(' · ', array_map(fn (string $use) => $uses[$use], $provider['uses'])) ?: 'Nothing Mainstay knows of' }}</p>
                            </div>
                            <p class="text-sm">{{ $keys[$provider['key'] ?? 'none'] }}</p>
                            @if ($provider['broken'])
                                <p role="alert" class="text-sm text-danger">Its config makes no provider: {{ $provider['broken'] }}</p>
                            @endif

                            @if ($provider['key'] !== 'config')
                                <form method="post" action="{{ route('mainstay.connectors.save', $provider['name']) }}" class="flex gap-2">
                                    @csrf
                                    <label for="key-{{ $provider['name'] }}" class="sr-only">{{ $provider['label'] }}'s key</label>
                                    <x-mainstay::input :id="'key-'.$provider['name']" name="key" type="password" autocomplete="new-password" required :placeholder="$provider['key'] === 'saved' ? 'A new key' : 'Key'" />
                                    <x-mainstay::button type="submit" variant="secondary">Save</x-mainstay::button>
                                </form>
                            @endif

                            <div class="flex gap-2">
                                <form method="post" action="{{ route('mainstay.connectors.test', $provider['name']) }}">
                                    @csrf
                                    <x-mainstay::button type="submit" variant="secondary">Test</x-mainstay::button>
                                </form>
                                @if (in_array($provider['key'], ['saved', 'unreadable'], true))
                                    <x-mainstay::button type="button" variant="secondary" data-dialog-open="forget-{{ $provider['name'] }}">Remove key</x-mainstay::button>
                                    <x-mainstay::dialog :id="'forget-'.$provider['name']" :label="'Remove '.$provider['label'].'\'s key?'">
                                        <form method="post" action="{{ route('mainstay.connectors.forget', $provider['name']) }}" class="space-y-4">
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

            <form id="connectors-defaults" method="post" action="{{ route('mainstay.connectors.defaults') }}">
                @csrf
                <x-mainstay::field-group label="Used for">
                    @foreach ($uses as $use => $label)
                        @php($able = array_values(array_filter($providers, fn (array $provider) => in_array($use, $provider['uses'], true))))
                        @continue($able === [])
                        <x-mainstay::field :label="$label" :for="'default-'.$use">
                            <x-mainstay::select :id="'default-'.$use" :name="'defaults['.$use.']'">
                                <option value="">As the site's config says: {{ $labels[$defaults[$use]['provider'] ?? ''] ?? 'none' }}</option>
                                @foreach ($able as $provider)
                                    <option value="{{ $provider['name'] }}" @selected($defaults[$use]['chosen'] && $defaults[$use]['provider'] === $provider['name'])>{{ $provider['label'] }}{{ in_array($provider['key'], [null, 'unreadable'], true) ? ' (no key)' : '' }}</option>
                                @endforeach
                            </x-mainstay::select>
                        </x-mainstay::field>
                    @endforeach
                </x-mainstay::field-group>
            </form>
        </div>
    </div>
</x-mainstay::admin>
