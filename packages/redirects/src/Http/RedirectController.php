<?php

namespace Mainstay\Redirects\Http;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Mainstay\Redirects\Miss;
use Mainstay\Redirects\MissLog;
use Mainstay\Redirects\Redirect;
use Mainstay\Redirects\RedirectForm;
use Mainstay\Redirects\RedirectLoop;
use Mainstay\Redirects\RedirectStore;
use Mainstay\Redirects\Resolution;
use Mainstay\Redirects\Resolver;

/*
 | The Redirects screen: the list with its filters and bulk actions, the
 | form that makes or changes one, and the box that says what an address
 | would do without asking for it. The plugin's routes answer only to whoever
 | holds `use_redirects`.
 */
class RedirectController
{
    private const PER_PAGE = 25;

    private const SORTS = ['created' => 'created_at', 'source' => 'source', 'hits' => 'hits', 'last_hit' => 'last_hit_at', 'priority' => 'priority'];

    private const BULK_ACTIONS = ['enable', 'disable', 'delete'];

    public function __construct(private RedirectStore $store, private RedirectForm $form, private Resolver $resolver, private MissLog $misses) {}

    public function index(Request $request): View
    {
        $sort = array_key_exists($request->query('sort'), self::SORTS) ? $request->query('sort') : 'created';
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';

        $redirects = $this->filtered($request)->orderBy(self::SORTS[$sort], $direction)->orderByDesc('id')
            ->paginate(self::PER_PAGE)->withQueryString();

        return view('redirects::index', [
            'redirects' => $redirects,
            'view' => ['query' => $request->query('query'), 'origin' => $request->query('origin'), 'type' => $request->query('type'), 'status' => $request->query('status'), 'enabled' => $request->query('enabled'), 'sort' => $sort, 'direction' => $direction],
            'tested' => $this->tested((string) $request->query('test')),
            'test' => (string) $request->query('test'),
            'missCount' => Miss::pendingCount(),
        ]);
    }

    public function create(Request $request): View
    {
        return $this->form(new Redirect([
            'source' => (string) $request->query('source'),
            'match_type' => Redirect::EXACT,
            'status' => Redirect::DEFAULT_STATUS,
            'preserve_query' => true,
            'enabled' => true,
            'priority' => Redirect::DEFAULT_PRIORITY,
        ]));
    }

    public function store(Request $request): RedirectResponse
    {
        $redirect = $this->store->save($this->form->apply($request->all(), new Redirect));

        if (! $redirect->isRegex()) {
            $this->misses->forget($redirect->source);
        }

        return redirect()->route('mainstay.plugins.redirects.index')->with('status', "Redirect from {$redirect->source} made.");
    }

    public function edit(int $redirect): View
    {
        return $this->form(Redirect::query()->findOrFail($redirect));
    }

    public function update(Request $request, int $redirect): RedirectResponse
    {
        $saved = $this->store->save($this->form->apply($request->all(), Redirect::query()->findOrFail($redirect)));

        return redirect()->route('mainstay.plugins.redirects.index')->with('status', "Redirect from {$saved->source} saved.");
    }

    public function toggle(int $redirect): RedirectResponse
    {
        $rule = Redirect::query()->findOrFail($redirect);
        $this->store->setEnabled([$rule->id], ! $rule->enabled);

        return redirect()->back()->with('status', $rule->enabled ? "{$rule->source} is off." : "{$rule->source} is on.");
    }

    public function destroy(int $redirect): RedirectResponse
    {
        $this->store->delete([Redirect::query()->findOrFail($redirect)->id]);

        return redirect()->route('mainstay.plugins.redirects.index')->with('status', 'Redirect deleted.');
    }

    public function bulk(Request $request): RedirectResponse
    {
        $chosen = $request->validate([
            'action' => ['required', Rule::in(self::BULK_ACTIONS)],
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ], ['ids.required' => 'Choose at least one redirect.']);

        $ids = array_map(intval(...), $chosen['ids']);

        $changed = match ($chosen['action']) {
            'enable' => $this->store->setEnabled($ids, true),
            'disable' => $this->store->setEnabled($ids, false),
            default => $this->store->delete($ids),
        };

        return redirect()->back()->with('status', $changed === 1 ? '1 redirect changed.' : "{$changed} redirects changed.");
    }

    private function form(Redirect $redirect): View
    {
        return view('redirects::form', ['redirect' => $redirect, 'statuses' => Redirect::STATUSES, 'missCount' => Miss::pendingCount()]);
    }

    private function filtered(Request $request): Builder
    {
        return Redirect::query()
            ->when($request->query('query'), function (Builder $query, string $text) {
                $like = '%'.addcslashes($text, '%_\\').'%';

                $query->where(fn (Builder $any) => $any->where('source', 'like', $like)->orWhere('target', 'like', $like)->orWhere('note', 'like', $like));
            })
            ->when(in_array($request->query('origin'), [Redirect::CUSTOM, Redirect::MOVED, Redirect::TRASHED], true), fn (Builder $query) => $query->where('origin', $request->query('origin')))
            ->when(in_array($request->query('type'), [Redirect::EXACT, Redirect::REGEX], true), fn (Builder $query) => $query->where('match_type', $request->query('type')))
            ->when(in_array((int) $request->query('status'), Redirect::STATUSES, true), fn (Builder $query) => $query->where('status', (int) $request->query('status')))
            ->when(in_array($request->query('enabled'), ['0', '1'], true), fn (Builder $query) => $query->where('enabled', $request->query('enabled') === '1'));
    }

    /**
     * What an address would come to, as the box reports it, or null for none asked.
     *
     * @return array{path: string, resolution: ?Resolution, location: ?string, loop: ?string}|null
     */
    private function tested(string $asked): ?array
    {
        if (trim($asked) === '') {
            return null;
        }

        $path = parse_url(trim($asked), PHP_URL_PATH) ?: '/';
        $query = parse_url(trim($asked), PHP_URL_QUERY);

        try {
            $resolution = $this->resolver->resolve($path);
        } catch (RedirectLoop $loop) {
            return ['path' => $path, 'resolution' => null, 'location' => null, 'loop' => $loop->getMessage()];
        }

        $redirects = $resolution !== null && ! $resolution->isGone();

        return ['path' => $path, 'resolution' => $resolution, 'location' => $redirects ? $resolution->location($query) : null, 'loop' => null];
    }
}
