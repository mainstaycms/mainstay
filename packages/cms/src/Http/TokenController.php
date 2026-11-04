<?php

namespace Mainstay\Http;

use Illuminate\Database\RecordNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Mainstay\Auth\Capabilities;
use Mainstay\Auth\Gate;
use Mainstay\Mainstay;

/*
 | The signed-in account's API tokens over Mainstay::tokens(): its own, made
 | and revoked by it alone, each holding what the grid ticks of what the
 | account itself holds. A new token's secret is flashed to the next page,
 | the one time it is shown.
 */
class TokenController
{
    /* The session key the new secret is flashed under. */
    public const SECRET = 'mainstay.token';

    public function __construct(private Mainstay $mainstay) {}

    public function index(): View
    {
        $me = Gate::user();

        return view('mainstay::tokens', [
            'tokens' => $this->mainstay->tokens()->of($me->getKey()),
            'sections' => RoleController::contentSections($this->mainstay->registered()),
            'covered' => fn (string $name) => Capabilities::uncovered($me, [$name]) === null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        [, $secret] = $this->mainstay->tokens()->issue(Gate::user()->getKey(), (string) $request->input('name'), RoleController::posted($request));

        return redirect()->route('mainstay.tokens')->with(self::SECRET, $secret)->with('status', 'Token made.');
    }

    public function destroy(int $id): RedirectResponse
    {
        try {
            $this->mainstay->tokens()->revoke($id);
        } catch (RecordNotFoundException) {
            throw new HttpResponseException(response()->view('mainstay::missing', status: 404));
        }

        return redirect()->route('mainstay.tokens')->with('status', 'Token revoked.');
    }
}
