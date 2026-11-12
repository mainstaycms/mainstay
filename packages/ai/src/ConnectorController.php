<?php

namespace Mainstay\Ai;

use Illuminate\Database\RecordNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/*
 | The Connectors screen over Connectors: every AI provider, its key saved,
 | removed or tested, and the one each kind of work goes to. The plugin's
 | routes answer only to whoever holds `use_ai`.
 */
class ConnectorController
{
    public function __construct(private Connectors $connectors) {}

    public function index(): View
    {
        return view('ai::index', [
            'providers' => $this->connectors->all(),
            'defaults' => $this->connectors->defaults(),
            'uses' => array_map(fn (array $use) => $use[2], Connectors::USES),
            'fallbacks' => array_combine(array_keys(Connectors::USES), array_map($this->connectors->fallbackModel(...), array_keys(Connectors::USES))),
        ]);
    }

    /* Every kind of work posted at once with the model to use for it, a blank
       provider left to the site's config and a blank model to the provider's
       own: all of them chosen or none. */
    public function defaults(Request $request): RedirectResponse
    {
        $chosen = (array) $request->input('defaults', []);
        $models = (array) $request->input('models', []);
        $this->assertModelsAreText($models);

        $this->found(fn () => DB::transaction(function () use ($chosen, $models) {
            foreach (array_keys(Connectors::USES) as $use) {
                if (array_key_exists($use, $chosen)) {
                    $this->connectors->choose(
                        $use,
                        is_string($chosen[$use]) && $chosen[$use] !== '' ? $chosen[$use] : null,
                        is_string($models[$use] ?? null) ? $models[$use] : null,
                    );
                }
            }
        }));

        return redirect()->route('mainstay.plugins.ai.index')->with('status', 'Defaults saved.');
    }

    /* A model posted as anything but text is refused rather than read as blank,
       which would clear the one saved. */
    private function assertModelsAreText(array $models): void
    {
        foreach (Connectors::USES as $use => $kind) {
            if (isset($models[$use]) && ! is_string($models[$use])) {
                throw ValidationException::withMessages(["models.{$use}" => 'The '.Str::lower($kind[2]).' model must be text.']);
            }
        }
    }

    /* A refused key goes back with its errors alone: Laravel's own redirect
       would keep it in the session as old input. */
    public function save(Request $request, string $provider): RedirectResponse
    {

        try {
            $this->found(fn () => $this->connectors->save($provider, is_string($key = $request->input('key')) ? $key : ''));
        } catch (ValidationException $exception) {
            return redirect()->route('mainstay.plugins.ai.index')->withErrors($exception->errors());
        }

        return redirect()->route('mainstay.plugins.ai.index')->with('status', "{$this->connectors->label($provider)}'s key saved.");
    }

    public function forget(string $provider): RedirectResponse
    {
        $this->found(fn () => $this->connectors->forget($provider));

        return redirect()->route('mainstay.plugins.ai.index')->with('status', "{$this->connectors->label($provider)}'s key removed.");
    }

    public function test(string $provider): RedirectResponse
    {
        $reply = null;
        $this->found(function () use ($provider, &$reply) {
            $reply = $this->connectors->test($provider);
        });

        return redirect()->route('mainstay.plugins.ai.index')->with('status', "{$this->connectors->label($provider)} answered: {$reply}");
    }

    private function found(callable $write): void
    {
        try {
            $write();
        } catch (RecordNotFoundException) {
            throw $this->missing();
        }
    }

    private function missing(): HttpResponseException
    {
        return new HttpResponseException(response()->view('mainstay::missing', status: 404));
    }
}
