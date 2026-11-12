<?php

namespace Mainstay\Seo\Http;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Mainstay\Mainstay;
use Mainstay\Media\Library;
use Mainstay\Seo\LocaleSettings;
use Mainstay\Settings\Setting;

/*
 | What the SEO plugin keeps per language: the title template and the image a
 | page with none of its own is shared with. Inside Settings, so only for
 | whoever may manage settings, which the route's middleware asks.
 */
class SettingsController
{
    public function __construct(private Mainstay $mainstay, private LocaleSettings $settings) {}

    public function show(): View
    {
        return view('seo::settings', ['locales' => array_keys($this->mainstay->locales()), 'settings' => $this->settings]);
    }

    public function update(Request $request): RedirectResponse
    {
        $rules = [];

        foreach (array_keys($this->mainstay->locales()) as $locale) {
            $rules["locales.{$locale}.title_template"] = ['nullable', 'string', 'max:'.LocaleSettings::TEMPLATE_MAX, $this->namesTitle(...)];
            $rules["locales.{$locale}.default_image"] = ['nullable', 'integer', 'min:1', $this->inLibrary(...)];
        }

        $request->merge(['locales' => $this->unix($request->input('locales'))]);
        $posted = $request->validate($rules)['locales'] ?? [];

        foreach (array_keys($this->mainstay->locales()) as $locale) {
            $template = trim((string) ($posted[$locale]['title_template'] ?? ''));
            $image = $posted[$locale]['default_image'] ?? null;

            $this->settings->save($locale, $template === '' ? null : $template, $image === null ? null : (int) $image);
        }

        return redirect()->route('mainstay.settings.seo.index')->with('status', 'SEO settings saved.');
    }

    /* A template without the page's own title would give every page one title. */
    private function namesTitle(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && trim($value) !== '' && ! str_contains($value, '%title%')) {
            $fail('The template must contain %title%, where the page\'s own title goes.');
        }
    }

    private function inLibrary(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value !== null && $this->mainstay->media()->present([(int) $value], Library::imageTypes()) === []) {
            $fail('That image is not in the media library.');
        }
    }

    private function unix(mixed $posted): mixed
    {
        return is_array($posted) ? array_map($this->unix(...), $posted) : (is_string($posted) ? Setting::unix($posted) : $posted);
    }
}
