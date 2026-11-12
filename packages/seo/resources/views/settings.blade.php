@php
    $image = new \Mainstay\Fields\Image(label: 'Default social image', sizes: ['preview' => [640]]);
@endphp

{{--
 | What the SEO plugin keeps for each language: the title template and the
 | image a page with none of its own is shared with. The site-wide settings
 | are on General. Ruled with hairlines as the admin's other screens are.
--}}
<x-mainstay::settings-screen title="SEO">
    <x-slot:actions>
        <x-mainstay::button type="submit" form="seo-settings-form">Save</x-mainstay::button>
    </x-slot:actions>

    <form id="seo-settings-form" method="post" action="{{ route('mainstay.settings.seo.update') }}" data-dirty-form>
        @csrf
        <div class="px-5 pt-5 empty:hidden"><x-mainstay::notices /></div>

        @foreach ($locales as $locale)
            @php
                $template = old("locales.{$locale}.title_template", $settings->template($locale) === \Mainstay\Seo\LocaleSettings::DEFAULT_TEMPLATE ? null : $settings->template($locale));
                $picked = old("locales.{$locale}.default_image", $settings->imageId($locale));
                $messages = $errors->get("locales.{$locale}.title_template");
            @endphp
            <section aria-labelledby="seo-locale-{{ $locale }}">
                <h2 id="seo-locale-{{ $locale }}" class="border-b border-border px-5 py-2.5 text-xs font-medium text-muted">{{ strtoupper($locale) }}</h2>

                <div class="border-b border-border px-5 py-3.5">
                    <label for="seo-template-{{ $locale }}" class="block pb-1.5 text-xs font-medium text-muted">Title template</label>
                    <x-mainstay::input :id="'seo-template-'.$locale" :name="'locales['.$locale.'][title_template]'" :value="$template" :placeholder="\Mainstay\Seo\LocaleSettings::DEFAULT_TEMPLATE" :maxlength="\Mainstay\Seo\LocaleSettings::TEMPLATE_MAX" :aria-invalid="$messages ? 'true' : null" :aria-describedby="$messages ? 'seo-template-'.$locale.'-error' : 'seo-template-'.$locale.'-hint'" />
                    <p id="seo-template-{{ $locale }}-hint" class="pt-1.5 text-xs text-muted">%title% is the page's own title and %site% the site name. Empty uses {{ \Mainstay\Seo\LocaleSettings::DEFAULT_TEMPLATE }}.</p>
                    <x-mainstay::messages :id="'seo-template-'.$locale.'-error'" :messages="$messages" />
                </div>

                <div class="border-b border-border">
                    <x-dynamic-component component="mainstay::fields.image" :field="$image" :name="'locales['.$locale.'][default_image]'" :value="$picked" :messages="$errors->get('locales.'.$locale.'.default_image')" />
                </div>
            </section>
        @endforeach
    </form>
</x-mainstay::settings-screen>
