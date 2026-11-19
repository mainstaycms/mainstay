<?php

namespace Mainstay\Redirects\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Mainstay\Redirects\Miss;

/*
 | The Misses tab: the addresses visitors found nothing at, the most asked
 | for first by default, each one to make a redirect from or to ignore.
 */
class MissController
{
    private const PER_PAGE = 25;

    private const SORTS = ['hits' => 'hits', 'last_seen' => 'last_seen_at', 'address' => 'address'];

    public function index(Request $request): View
    {
        $sort = array_key_exists($request->query('sort'), self::SORTS) ? $request->query('sort') : 'hits';
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';
        $ignored = $request->query('show') === 'ignored';

        return view('redirects::misses', [
            'misses' => Miss::query()->where('ignored', $ignored)->orderBy(self::SORTS[$sort], $direction)->orderByDesc('id')->paginate(self::PER_PAGE)->withQueryString(),
            'view' => ['sort' => $sort, 'direction' => $direction, 'show' => $ignored ? 'ignored' : null],
            'missCount' => Miss::pendingCount(),
        ]);
    }

    public function ignore(int $miss): RedirectResponse
    {
        return $this->setIgnored($miss, true);
    }

    public function unignore(int $miss): RedirectResponse
    {
        return $this->setIgnored($miss, false);
    }

    private function setIgnored(int $id, bool $ignored): RedirectResponse
    {
        Miss::query()->findOrFail($id)->update(['ignored' => $ignored]);

        return redirect()->back();
    }
}
