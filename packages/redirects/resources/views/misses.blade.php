@php
    $link = fn (array $patch) => '?'.http_build_query(array_filter(array_merge($view, $patch), fn ($value) => $value !== null && $value !== ''));
    $columns = ['address' => 'Address', 'hits' => 'Times asked', 'last_seen' => 'Last asked'];
    $cell = 'px-5 py-3.5 align-middle';
    $showingIgnored = $view['show'] === 'ignored';
@endphp

{{--
 | The addresses visitors asked for and found nothing at. "Redirect this"
 | opens the new-redirect form with the address filled in, and the miss goes
 | when the redirect is made; Ignore keeps an address out of this list.
--}}
<x-mainstay::admin title="Redirects" :breadcrumb="[['label' => 'Redirects', 'href' => route('mainstay.plugins.redirects.index')], ['label' => $showingIgnored ? 'Ignored misses' : 'Misses']]">
    <x-slot:actions>
        <a href="{{ $link(['show' => $showingIgnored ? null : 'ignored', 'page' => null]) }}" class="rounded-control px-2 py-1 text-sm text-muted hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">{{ $showingIgnored ? 'Show the misses' : 'Show ignored' }}</a>
    </x-slot:actions>

    <div class="flex h-full min-h-0 flex-col">
        @include('redirects::partials.nav', ['current' => 'misses'])

        <div class="min-h-0 flex-1 overflow-auto">
            <x-mainstay::notices :messages="$errors->all()" class="px-6 pt-4" />

            @if ($misses->total() === 0)
                <div class="grid place-content-center px-4 py-16 text-center">
                    <p class="text-sm font-medium">{{ $showingIgnored ? 'Nothing ignored' : 'No misses yet' }}</p>
                    <p class="pt-1 text-sm text-muted">{{ $showingIgnored ? 'Addresses you ignore wait here.' : 'An address visitors ask for that leads to a 404 shows up here, counted.' }}</p>
                </div>
            @else
                <table aria-label="Misses" class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-muted [&>th]:sticky [&>th]:top-0 [&>th]:z-10 [&>th]:bg-canvas [&>th]:shadow-[inset_0_-1px_0_var(--color-border)]">
                            @foreach ($columns as $field => $heading)
                                @php($active = $view['sort'] === $field)
                                <th scope="col" @if ($active) aria-sort="{{ $view['direction'] === 'asc' ? 'ascending' : 'descending' }}" @endif class="whitespace-nowrap px-5 py-3 font-medium">
                                    <a href="{{ $link(['sort' => $field, 'direction' => $active && $view['direction'] === 'asc' ? 'desc' : 'asc', 'page' => null]) }}" class="rounded-control hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">{{ $heading }}<span class="sr-only">, sort</span></a>
                                </th>
                            @endforeach
                            <th scope="col" class="px-5 py-3 font-medium">Last referrer</th>
                            <th scope="col" class="px-5 py-3"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($misses as $miss)
                            <tr class="border-b border-border last:border-b-0">
                                <td class="{{ $cell }} max-w-md truncate font-medium" title="{{ $miss->address }}">{{ $miss->address }}</td>
                                <td class="{{ $cell }} tabular-nums text-muted">{{ number_format($miss->hits) }}</td>
                                <td class="{{ $cell }} whitespace-nowrap text-muted"><x-mainstay::date-text :iso="$miss->last_seen_at->toIso8601String()" /></td>
                                <td class="{{ $cell }} max-w-sm truncate text-muted" title="{{ $miss->referrer }}">{{ $miss->referrer ?? 'None' }}</td>
                                <td class="{{ $cell }}">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ route('mainstay.plugins.redirects.create', ['source' => $miss->address]) }}" class="inline-flex items-center whitespace-nowrap rounded-control border border-border bg-surface px-3 py-1.5 text-sm font-medium hover:bg-canvas focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">Redirect this<span class="sr-only">: {{ $miss->address }}</span></a>
                                        <form method="post" action="{{ route($showingIgnored ? 'mainstay.plugins.redirects.misses.unignore' : 'mainstay.plugins.redirects.misses.ignore', $miss->id) }}">
                                            @csrf
                                            <x-mainstay::button type="submit" variant="secondary">{{ $showingIgnored ? 'Stop ignoring' : 'Ignore' }}<span class="sr-only">: {{ $miss->address }}</span></x-mainstay::button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <x-mainstay::pager :start="($misses->currentPage() - 1) * $misses->perPage()" :shown="$misses->count()" :total="$misses->total()" :page="$misses->currentPage()" :page-count="max($misses->lastPage(), 1)" :href="fn (int $to) => $misses->url($to)" />
    </div>
</x-mainstay::admin>
