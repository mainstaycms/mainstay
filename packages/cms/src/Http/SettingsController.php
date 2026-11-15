<?php

namespace Mainstay\Http;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Mainstay\Mainstay;
use Mainstay\Navigation;
use Mainstay\Settings\Setting;

/*
 | The Settings screen: every plugin's settings as a section under its label,
 | for whoever holds `manage_settings`, and one Save. What is drawn is what is
 | written -- Navigation::settings(), asked again on the write -- so a name
 | posted for a setting that was not drawn for this user is never stored, and
 | a plugin's key is only ever read from under its own handle.
 */
class SettingsController
{
    public function __construct(private Mainstay $mainstay) {}

    public function show(): View
    {
        $this->sees();

        return view('mainstay::settings', ['sections' => Navigation::settings()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->sees();
        $rules = [];
        $keys = [];

        foreach (Navigation::settings() as $section) {
            foreach ($section['settings'] as $setting) {
                $path = "settings.{$section['handle']}.{$setting->key}";
                $rules[$path] = $setting->rules();
                $keys[$path] = [$section['handle'].'.'.$setting->key, $setting];
            }
        }

        $request->merge(['settings' => $this->unix($request->input('settings'))]);
        $posted = $request->validate($rules);

        DB::transaction(function () use ($keys, $posted) {
            foreach ($keys as $path => [$key, $setting]) {
                if (Arr::has($posted, $path)) {
                    $this->mainstay->setSetting($key, $setting->fromForm(Arr::get($posted, $path)));
                }
            }
        });

        return redirect()->route('mainstay.settings')->with('status', 'Settings saved.');
    }

    /* Line ends before validation, so a length rule counts what is stored. */
    private function unix(mixed $posted): mixed
    {
        return is_array($posted) ? array_map($this->unix(...), $posted) : (is_string($posted) ? Setting::unix($posted) : $posted);
    }

    private function sees(): void
    {
        if (! Navigation::manages()) {
            throw new HttpResponseException(response()->view('mainstay::missing', status: 404));
        }
    }
}
