<?php

namespace Mainstay\Ai;

use Illuminate\Database\RecordNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
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
    public function __construct(private Connectors $connectors, private ModelCatalog $catalog) {}

    public function index(): View
    {
        $providers = $this->connectors->all();
        $defaults = $this->connectors->defaults();
        $selections = $this->selections($defaults, $providers);
        $lists = $this->catalog->forUses(array_map(fn (string $use) => $selections[$use]['provider'] ?? $this->connectors->hostedProvider($use), array_combine(array_keys($selections), array_keys($selections))));

        return view('ai::index', [
            'providers' => $providers,
            'defaults' => $defaults,
            'uses' => array_map(fn (array $use) => $use[2], Connectors::USES),
            'fallbacks' => array_combine(array_keys(Connectors::USES), array_map($this->connectors->fallbackModel(...), array_keys(Connectors::USES))),
            'selections' => $selections,
            'lists' => $lists,
        ]);
    }

    /* The models one provider offers for one kind of work, for the screen to
       fill the model field as the provider select changes. */
    public function models(Request $request): JsonResponse
    {
        $provider = $request->query('provider');
        $use = $request->query('use');

        if (! is_string($provider) || ! is_string($use) || ! array_key_exists($use, Connectors::USES) || ! array_key_exists($provider, $this->connectors->providers())) {
            throw $this->missing();
        }

        $list = $this->catalog->for($provider, $use);

        return response()->json(['models' => $list->models, 'note' => $list->note, 'default' => $this->connectors->fallbackModel($use, $provider)]);
    }

    public function refreshModels(): RedirectResponse
    {
        $this->catalog->forget();

        return redirect()->route('mainstay.plugins.ai.index')->with('status', 'Model lists will be fetched again.');
    }

    /* After a refusal, the pair the person posted comes back together; a kind
       they did not post shows what is saved. A blank provider is left to the
       site's config is shown the models of the provider the config names when
       that one has a key, and saving a model sets it as the use's provider.

       @return array<string, array{provider: string|null, model: string|null, pin: array{name: string, label: string, default: string|null}|null}> */
    private function selections(array $defaults, array $providers): array
    {
        $selections = [];
        $keyed = array_column(array_filter($providers, fn (array $provider) => in_array($provider['key'], ['config', 'saved'], true)), 'label', 'name');

        foreach (array_keys(Connectors::USES) as $use) {
            $posted = array_key_exists($use, (array) old('defaults', []));
            $provider = $posted ? (string) old('defaults.'.$use) : ($defaults[$use]['chosen'] ? (string) $defaults[$use]['provider'] : '');
            $configured = (string) $this->connectors->hostedProvider($use);
            $selections[$use] = [
                'provider' => $provider === '' ? null : $provider,
                'model' => $posted ? old('models.'.$use) : $defaults[$use]['model'],
                'pin' => isset($keyed[$configured]) ? ['name' => $configured, 'label' => $keyed[$configured], 'default' => $this->connectors->fallbackModel($use, $configured)] : null,
            ];
        }

        return $selections;
    }

    /* Every kind of work posted at once with the model to use for it, a blank
       provider left to the site's config and a blank model to the provider's
       own: all of them chosen or none. A model for a use left to the site's
       config is kept by choosing the provider the config names, so it is the
       one that is asked; where the config names none with a key, that is
       refused rather than the model dropped. */
    public function defaults(Request $request): RedirectResponse
    {
        $chosen = (array) $request->input('defaults', []);
        $models = (array) $request->input('models', []);
        $this->assertModelsAreText($models);

        $this->found(fn () => DB::transaction(function () use ($chosen, $models) {
            foreach (array_keys(Connectors::USES) as $use) {
                if (array_key_exists($use, $chosen)) {
                    $model = is_string($models[$use] ?? null) ? $models[$use] : null;
                    $this->connectors->choose($use, $this->providerToSet($use, is_string($chosen[$use]) && $chosen[$use] !== '' ? $chosen[$use] : null, $model), $model);
                }
            }
        }));

        return redirect()->route('mainstay.plugins.ai.index')->with('status', 'Defaults saved.');
    }

    private function providerToSet(string $use, ?string $chosen, ?string $model): ?string
    {
        if ($chosen !== null || trim((string) $model) === '') {
            return $chosen;
        }

        $configured = $this->connectors->hostedProvider($use);

        if ($configured === null || ! $this->connectors->hasKey($configured)) {
            throw ValidationException::withMessages(["models.{$use}" => 'Choose a provider to set a model.']);
        }

        return $configured;
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
