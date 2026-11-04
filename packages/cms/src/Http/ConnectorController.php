<?php

namespace Mainstay\Http;

use Illuminate\Database\RecordNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Mainstay\Ai\Connectors;
use Mainstay\Mainstay;
use Mainstay\Navigation;

/*
 | The Connectors screen over Mainstay::connectors(): every AI provider, its
 | key saved, removed or tested, and the one each kind of work goes to. Not
 | there to anyone who may not manage them, as the accounts' screens are not.
 */
class ConnectorController
{
    public function __construct(private Mainstay $mainstay) {}

    public function index(): View
    {
        $this->sees();
        $connectors = $this->mainstay->connectors();

        return view('mainstay::connectors', [
            'providers' => $connectors->all(),
            'defaults' => $connectors->defaults(),
            'uses' => array_map(fn (array $use) => $use[2], Connectors::USES),
        ]);
    }

    /* Every kind of work posted at once, a blank one left to the site's
       config: all of them chosen or none. */
    public function defaults(Request $request): RedirectResponse
    {
        $this->sees();
        $chosen = (array) $request->input('defaults', []);

        $this->found(fn () => DB::transaction(function () use ($chosen) {
            foreach (array_keys(Connectors::USES) as $use) {
                if (array_key_exists($use, $chosen)) {
                    $this->mainstay->connectors()->choose($use, is_string($chosen[$use]) && $chosen[$use] !== '' ? $chosen[$use] : null);
                }
            }
        }));

        return redirect()->route('mainstay.connectors')->with('status', 'Defaults saved.');
    }

    /* A refused key goes back with its errors alone: Laravel's own redirect
       would keep it in the session as old input. */
    public function save(Request $request, string $provider): RedirectResponse
    {
        $this->sees();

        try {
            $this->found(fn () => $this->mainstay->connectors()->save($provider, is_string($key = $request->input('key')) ? $key : ''));
        } catch (ValidationException $exception) {
            return redirect()->route('mainstay.connectors')->withErrors($exception->errors());
        }

        return redirect()->route('mainstay.connectors')->with('status', "{$this->mainstay->connectors()->label($provider)}'s key saved.");
    }

    public function forget(string $provider): RedirectResponse
    {
        $this->sees();
        $this->found(fn () => $this->mainstay->connectors()->forget($provider));

        return redirect()->route('mainstay.connectors')->with('status', "{$this->mainstay->connectors()->label($provider)}'s key removed.");
    }

    public function test(string $provider): RedirectResponse
    {
        $this->sees();
        $reply = null;
        $this->found(function () use ($provider, &$reply) {
            $reply = $this->mainstay->connectors()->test($provider);
        });

        return redirect()->route('mainstay.connectors')->with('status', "{$this->mainstay->connectors()->label($provider)} answered: {$reply}");
    }

    private function sees(): void
    {
        if (! Navigation::connectors()) {
            throw $this->missing();
        }
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
