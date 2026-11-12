@php
    $seoValues = old($seoName, $seoMeta?->toArray() ?? []);
    $seoValues = is_array($seoValues) ? $seoValues : [];
    $seoErrors = fn (string $key) => $errors->get("{$seoName}.{$key}");
    $seoLabel = 'block pb-1.5 text-xs font-medium text-muted';
    $seoRow = 'border-b border-border px-5 py-3.5';
    $seoHead = 'border-b border-border px-5 py-2.5 text-xs font-medium text-muted';
@endphp

{{--
 | The SEO tab: the entry's search and social settings on the left, which post
 | with the form as `seo[...]`, and on the right what they look like and the
 | checks of the content, which seo.js draws and keeps up to date as the form
 | is edited. Without its script the inputs still work and the right side is
 | empty. Ruled with hairlines like the admin's other screens.
--}}
<div
    data-seo
    data-field="{{ $seoName }}"
    data-endpoint="{{ $seoEndpoint }}"
    data-locale="{{ $seoLocale }}"
    data-template="{{ $seoTemplate }}"
    data-site="{{ $seoSite }}"
    data-origin="{{ $seoOrigin }}"
    data-default-description="{{ $seoDescription }}"
    @if ($seoContent) data-content-field="{{ $seoContent }}" @endif
    @if ($seoSlug) data-slug-field="{{ $seoSlug }}" @endif
    class="min-h-0 flex-1 overflow-y-auto"
>
    <div class="grid lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <div>
            <section aria-labelledby="seo-keyphrase-heading">
                <h2 id="seo-keyphrase-heading" class="{{ $seoHead }}">Focus keyphrase</h2>
                <div class="{{ $seoRow }}">
                    <label for="seo-keyphrase" class="{{ $seoLabel }}">The phrase this page should be found for</label>
                    <x-mainstay::input id="seo-keyphrase" data-seo-input="keyphrase" :name="$seoName.'[keyphrase]'" :value="$seoValues['keyphrase'] ?? null" maxlength="100" :aria-invalid="$seoErrors('keyphrase') ? 'true' : null" aria-describedby="seo-keyphrase-hint" />
                    <p id="seo-keyphrase-hint" class="pt-1.5 text-xs text-muted">One to four words. The checks look for it in the title, the description, the headings and the first paragraph.</p>
                    <x-mainstay::messages id="seo-keyphrase-error" :messages="$seoErrors('keyphrase')" />
                </div>
            </section>

            <section aria-labelledby="seo-search-heading">
                <h2 id="seo-search-heading" class="{{ $seoHead }}">Search appearance</h2>
                <div class="{{ $seoRow }}">
                    <div class="flex items-baseline justify-between gap-2">
                        <label for="seo-title" class="{{ $seoLabel }}">SEO title</label>
                        <span data-seo-counter="title" class="text-xs tabular-nums text-muted"></span>
                    </div>
                    <x-mainstay::input id="seo-title" data-seo-input="title" :name="$seoName.'[title]'" :value="$seoValues['title'] ?? null" maxlength="200" aria-describedby="seo-title-hint" />
                    <p id="seo-title-hint" class="pt-1.5 text-xs text-muted">Empty uses the entry's title. The site's template is put around it: <span data-seo-template class="font-mono"></span></p>
                    <x-mainstay::messages id="seo-title-error" :messages="$seoErrors('title')" />
                </div>
                <div class="{{ $seoRow }}">
                    <div class="flex items-baseline justify-between gap-2">
                        <label for="seo-description" class="{{ $seoLabel }}">Meta description</label>
                        <span data-seo-counter="description" class="text-xs tabular-nums text-muted"></span>
                    </div>
                    <x-mainstay::textarea id="seo-description" data-seo-input="description" :name="$seoName.'[description]'" rows="3" maxlength="500" aria-describedby="seo-description-hint">{{ $seoValues['description'] ?? '' }}</x-mainstay::textarea>
                    <p id="seo-description-hint" class="pt-1.5 text-xs text-muted">Empty uses the site's default description.</p>
                    <x-mainstay::messages id="seo-description-error" :messages="$seoErrors('description')" />
                </div>
                <div class="{{ $seoRow }}">
                    <label for="seo-canonical" class="{{ $seoLabel }}">Canonical URL</label>
                    <x-mainstay::input id="seo-canonical" data-seo-input="canonical" :name="$seoName.'[canonical]'" type="url" :value="$seoValues['canonical'] ?? null" placeholder="https://" maxlength="2048" :aria-invalid="$seoErrors('canonical') ? 'true' : null" aria-describedby="seo-canonical-hint" />
                    <p id="seo-canonical-hint" class="pt-1.5 text-xs text-muted">Only for a page that is a copy of one elsewhere. Empty is this page's own address.</p>
                    <x-mainstay::messages id="seo-canonical-error" :messages="$seoErrors('canonical')" />
                </div>
                @foreach (['noindex' => ['Hide from search engines', 'Adds noindex, and leaves the page out of the sitemap.'], 'nofollow' => ['Do not follow its links', 'Adds nofollow.']] as $key => [$title, $explains])
                    <div class="{{ $seoRow }}">
                        <label for="seo-{{ $key }}" class="flex cursor-pointer items-start justify-between gap-3">
                            <span class="min-w-0">
                                <span class="block text-sm font-medium text-ink">{{ $title }}</span>
                                <span id="seo-{{ $key }}-hint" class="mt-1 block text-xs text-muted">{{ $explains }}</span>
                            </span>
                            <input type="hidden" name="{{ $seoName }}[{{ $key }}]" value="0">
                            <x-mainstay::switch id="seo-{{ $key }}" data-seo-input="{{ $key }}" :name="$seoName.'['.$key.']'" value="1" :label="$title" :checked="filter_var($seoValues[$key] ?? false, FILTER_VALIDATE_BOOL)" :aria-describedby="'seo-'.$key.'-hint'" />
                        </label>
                        <x-mainstay::messages :id="'seo-'.$key.'-error'" :messages="$seoErrors($key)" />
                    </div>
                @endforeach
            </section>

            <section aria-labelledby="seo-social-heading">
                <h2 id="seo-social-heading" class="{{ $seoHead }}">Social sharing</h2>
                <div class="{{ $seoRow }}">
                    <label for="seo-social-title" class="{{ $seoLabel }}">Social title</label>
                    <x-mainstay::input id="seo-social-title" data-seo-input="social_title" :name="$seoName.'[social_title]'" :value="$seoValues['social_title'] ?? null" maxlength="200" aria-describedby="seo-social-title-hint" />
                    <p id="seo-social-title-hint" class="pt-1.5 text-xs text-muted">Empty uses the entry's title.</p>
                    <x-mainstay::messages id="seo-social-title-error" :messages="$seoErrors('social_title')" />
                </div>
                <div class="{{ $seoRow }}">
                    <label for="seo-social-description" class="{{ $seoLabel }}">Social description</label>
                    <x-mainstay::textarea id="seo-social-description" data-seo-input="social_description" :name="$seoName.'[social_description]'" rows="2" maxlength="500" aria-describedby="seo-social-description-hint">{{ $seoValues['social_description'] ?? '' }}</x-mainstay::textarea>
                    <p id="seo-social-description-hint" class="pt-1.5 text-xs text-muted">Empty uses the meta description.</p>
                    <x-mainstay::messages id="seo-social-description-error" :messages="$seoErrors('social_description')" />
                </div>
                <div data-seo-image class="border-b border-border">
                    <x-dynamic-component component="mainstay::fields.image" :field="$seoImage" :name="$seoName.'[social_image]'" :value="$seoValues['social_image'] ?? $seoMeta?->socialImage" :messages="$seoErrors('social_image')" />
                </div>
            </section>
        </div>

        <div class="border-t border-border lg:border-l lg:border-t-0">
            <p data-seo-note role="status" class="empty:hidden border-b border-border px-5 py-3 text-sm text-muted"></p>

            <section aria-labelledby="seo-google-heading">
                <h2 id="seo-google-heading" class="{{ $seoHead }}">Search result preview</h2>
                <div class="border-b border-border px-5 py-4">
                    <p data-seo-snippet="url" class="truncate text-xs text-muted"></p>
                    <p data-seo-snippet="title" class="break-words pt-0.5 text-lg leading-snug text-accent"></p>
                    <p data-seo-snippet="description" class="break-words pt-1 text-sm text-muted"></p>
                </div>
            </section>

            <section aria-labelledby="seo-card-heading">
                <h2 id="seo-card-heading" class="{{ $seoHead }}">Social preview</h2>
                <div class="border-b border-border px-5 py-4">
                    <div data-seo-card class="max-w-md overflow-hidden rounded-control border border-border">
                        <img data-seo-card-image alt="" hidden class="aspect-[1.91/1] w-full bg-surface object-cover">
                        <div class="space-y-0.5 bg-surface px-3 py-2">
                            <p data-seo-card-host class="truncate text-xs uppercase text-muted"></p>
                            <p data-seo-card-title class="break-words text-sm font-medium"></p>
                            <p data-seo-card-description class="break-words text-xs text-muted"></p>
                        </div>
                    </div>
                </div>
            </section>

            @foreach (['seo' => 'SEO analysis', 'readability' => 'Readability'] as $group => $heading)
                <section aria-labelledby="seo-{{ $group }}-heading">
                    <h2 id="seo-{{ $group }}-heading" class="{{ $seoHead }}">{{ $heading }}</h2>
                    <p data-seo-summary="{{ $group }}" class="border-b border-border px-5 py-3 text-sm empty:hidden"></p>
                    <ul data-seo-checks="{{ $group }}" class="[&>li]:border-b [&>li]:border-border"></ul>
                </section>
            @endforeach
        </div>
    </div>
</div>
