<?php

namespace Mainstay\Ai;

use Illuminate\Database\RecordNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        ]);
    }

    /* Every kind of work posted at once, a blank one left to the site's
       config: all of them chosen or none. */
    public function defaults(Request $request): RedirectResponse
    {
        $chosen = (array) $request->input('defaults', []);

        $this->found(fn () => DB::transaction(function () use ($chosen) {
            foreach (array_keys(Connectors::USES) as $use) {
                if (array_key_exists($use, $chosen)) {
                    $this->connectors->choose($use, is_string($chosen[$use]) && $chosen[$use] !== '' ? $chosen[$use] : null);
                }
            }
        }));

        return redirect()->route('mainstay.plugins.ai.index')->with('status', 'Defaults saved.');
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
