{{--
 | The site's settings, a section for each plugin that has some the user may
 | change, under the plugin's own name, and one Save for all of them. A
 | plugin whose generic form is not enough has a screen of its own, which
 | Settings' navigation lists beside this one.
--}}
<x-mainstay::settings-screen title="General">
    @if ($sections !== [])
        <x-slot:actions>
            <x-mainstay::button type="submit" form="settings-form">Save</x-mainstay::button>
        </x-slot:actions>
    @endif

    <form id="settings-form" method="post" action="{{ route('mainstay.settings.update') }}" data-dirty-form>
        @csrf
        <div class="space-y-6 px-6 py-8">
            <x-mainstay::notices />

            @forelse ($sections as $section)
                <x-mainstay::field-group :label="$section['label']">
                    @foreach ($section['settings'] as $setting)
                        @php($name = "settings[{$section['handle']}][{$setting->key}]")
                        <x-mainstay::setting
                            :setting="$setting"
                            :name="$name"
                            :value="old('settings.'.$section['handle'].'.'.$setting->key, \Mainstay\Facades\Mainstay::setting($section['handle'].'.'.$setting->key))"
                            :messages="$errors->get('settings.'.$section['handle'].'.'.$setting->key)"
                        />
                    @endforeach
                </x-mainstay::field-group>
            @empty
                <p class="text-sm text-muted">No plugin has settings for you to change.</p>
            @endforelse
        </div>
    </form>
</x-mainstay::settings-screen>
