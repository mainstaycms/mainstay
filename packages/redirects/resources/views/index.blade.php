@php
    $link = fn (array $patch) => '?'.http_build_query(array_filter(array_merge($view, ['test' => $test], $patch), fn ($value) => $value !== null && $value !== ''));
    $columns = ['source' => 'From', 'target' => 'To', 'status' => 'Status', 'origin' => 'Made by', 'hits' => 'Hits', 'last_hit' => 'Last hit'];
    $sortable = ['source', 'hits', 'last_hit'];
    $cell = 'px-5 py-3.5 align-middle';
    $origins = ['custom' => 'Owner', 'moved' => 'A moved page', 'trashed' => 'A trashed page'];
    $statuses = [301 => '301 Moved permanently', 302 => '302 Found', 307 => '307 Temporary', 308 => '308 Permanent', 410 => '410 Gone'];
    $controls = 'rounded-control border border-border bg-surface px-2.5 py-1 text-sm';
@endphp

{{--
 | The redirects: a box that says what an address would do, the filters, and
 | the list with its bulk actions. Filters and sort are in the query string;
 | the rows' checkboxes belong to the bulk form by its id, since a form
 | cannot sit inside the rows' own.
--}}
<x-mainstay::admin title="Redirects" :breadcrumb="[['label' => 'Redirects']]">
    <x-slot:actions>
        <a href="{{ route('mainstay.plugins.redirects.create') }}" class="inline-flex items-center rounded-control bg-accent px-3 py-1.5 text-sm font-medium text-accent-ink hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">New redirect</a>
    </x-slot:actions>

    <div class="flex h-full min-h-0 flex-col">
        @include('redirects::partials.nav', ['current' => 'redirects'])

        <div class="min-h-0 flex-1 overflow-auto">
            <div class="space-y-4 px-6 py-5">
                <x-mainstay::notices :messages="$errors->all()" />

                <form method="get" class="space-y-2" aria-label="Test an address">
                    @foreach ($view as $held => $value)
                        @if ($value !== null && $value !== '')
                            <input type="hidden" name="{{ $held }}" value="{{ $value }}">
                        @endif
                    @endforeach
                    <label for="test-address" class="block text-xs font-medium text-muted">Test an address</label>
                    <div class="flex gap-2">
                        <x-mainstay::input id="test-address" name="test" :value="$test" placeholder="/old-blog/my-post?utm_source=mail" aria-describedby="test-hint" />
                        <x-mainstay::button type="submit" variant="secondary">Test</x-mainstay::button>
                    </div>
                    <p id="test-hint" class="text-xs text-muted">Shows what a visitor asking for this address would get, without asking for it.</p>

                    @if ($tested !== null)
                        <div role="status" class="space-y-1 rounded-control border border-border bg-surface px-3 py-2 text-sm">
                            @if ($tested['loop'])
                                <p class="text-danger">{{ $tested['loop'] }}</p>
                            @elseif ($tested['resolution'] === null)
                                <p>No redirect applies to <code>{{ $tested['path'] }}</code>: the site answers it.</p>
                            @else
                                @foreach ($tested['resolution']->rules as $rule)
                                    <p class="text-muted">{{ $loop->iteration }}. <code>{{ $rule->source }}</code> ({{ $rule->isRegex() ? 'pattern' : 'address' }}, {{ $rule->status }}) <a href="{{ route('mainstay.plugins.redirects.edit', $rule->id) }}" class="underline">Edit</a></p>
                                @endforeach
                                <p class="font-medium">
                                    @if ($tested['resolution']->isGone())
                                        <code>{{ $tested['path'] }}</code> answers 410 Gone.
                                    @else
                                        <code>{{ $tested['path'] }}</code> answers {{ $tested['resolution']->status }} and goes to <code>{{ $tested['location'] }}</code>.
                                    @endif
                                </p>
                            @endif
                        </div>
                    @endif
                </form>

                <form method="get" class="flex flex-wrap items-center gap-2" aria-label="Filter the redirects">
                    @if ($test !== '')
                        <input type="hidden" name="test" value="{{ $test }}">
                    @endif
                    @foreach (['sort' => $view['sort'], 'direction' => $view['direction']] as $held => $value)
                        <input type="hidden" name="{{ $held }}" value="{{ $value }}">
                    @endforeach
                    <x-mainstay::input size="sm" ground="surface" :full-width="false" type="search" name="query" :value="$view['query']" placeholder="Search" aria-label="Search the redirects" class="w-48" />
                    <select name="origin" aria-label="Made by" class="{{ $controls }}">
                        <option value="">Made by: any</option>
                        @foreach ($origins as $value => $label)
                            <option value="{{ $value }}" @selected($view['origin'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <select name="type" aria-label="Kind" class="{{ $controls }}">
                        <option value="">Kind: any</option>
                        <option value="exact" @selected($view['type'] === 'exact')>Address</option>
                        <option value="regex" @selected($view['type'] === 'regex')>Pattern</option>
                    </select>
                    <select name="status" aria-label="Status" class="{{ $controls }}">
                        <option value="">Status: any</option>
                        @foreach ($statuses as $value => $label)
                            <option value="{{ $value }}" @selected((string) $view['status'] === (string) $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <select name="enabled" aria-label="On or off" class="{{ $controls }}">
                        <option value="">On or off</option>
                        <option value="1" @selected($view['enabled'] === '1')>On</option>
                        <option value="0" @selected($view['enabled'] === '0')>Off</option>
                    </select>
                    <x-mainstay::button type="submit" variant="secondary">Filter</x-mainstay::button>
                    <a href="{{ route('mainstay.plugins.redirects.index') }}" class="text-sm text-muted hover:text-ink">Reset</a>
                </form>
            </div>

            <form id="bulk-form" method="post" action="{{ route('mainstay.plugins.redirects.bulk') }}">@csrf</form>

            @if ($redirects->total() === 0)
                <div class="grid place-content-center px-4 py-16 text-center">
                    <p class="text-sm font-medium">No redirects here</p>
                    <p class="pt-1 text-sm text-muted">Change a page's address or move it to the trash and one is made for it, or make one yourself.</p>
                </div>
            @else
                <div class="flex flex-wrap items-center gap-2 border-y border-border bg-surface px-6 py-3">
                    <span class="text-sm text-muted">With the ones ticked:</span>
                    <x-mainstay::button type="submit" variant="secondary" form="bulk-form" name="action" value="enable">Turn on</x-mainstay::button>
                    <x-mainstay::button type="submit" variant="secondary" form="bulk-form" name="action" value="disable">Turn off</x-mainstay::button>
                    <x-mainstay::button type="button" variant="danger" data-dialog-open="delete-ticked">Delete</x-mainstay::button>
                </div>

                <table aria-label="Redirects" class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-muted [&>th]:sticky [&>th]:top-0 [&>th]:z-10 [&>th]:bg-canvas [&>th]:shadow-[inset_0_-1px_0_var(--color-border)]">
                            <th scope="col" class="w-12 px-5 py-3"><span class="sr-only">Select</span></th>
                            @foreach ($columns as $field => $heading)
                                @php($active = $view['sort'] === $field)
                                <th scope="col" @if ($active) aria-sort="{{ $view['direction'] === 'asc' ? 'ascending' : 'descending' }}" @endif class="whitespace-nowrap px-5 py-3 font-medium">
                                    @if (in_array($field, $sortable, true))
                                        <a href="{{ $link(['sort' => $field, 'direction' => $active && $view['direction'] === 'asc' ? 'desc' : 'asc', 'page' => null]) }}" class="rounded-control hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">{{ $heading }}<span class="sr-only">, sort</span></a>
                                    @else
                                        {{ $heading }}
                                    @endif
                                </th>
                            @endforeach
                            <th scope="col" class="w-10"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($redirects as $redirect)
                            <tr class="group border-b border-border last:border-b-0 has-[[data-select-row]:checked]:bg-surface">
                                <td class="w-12 px-5 py-3.5 align-middle">
                                    <x-mainstay::checkbox data-select-row form="bulk-form" name="ids[]" :value="$redirect->id" :label="'Select '.$redirect->source" />
                                </td>
                                <td class="{{ $cell }} max-w-md">
                                    <a href="{{ route('mainstay.plugins.redirects.edit', $redirect->id) }}" title="{{ $redirect->source }}" class="block truncate rounded-control font-medium hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">{{ $redirect->source }}</a>
                                    @if ($redirect->isRegex())
                                        <span class="ml-2 text-xs text-muted">Pattern</span>
                                    @endif
                                    @unless ($redirect->enabled)
                                        <span class="ml-2 text-xs text-muted">Off</span>
                                    @endunless
                                    @if ($redirect->last_error)
                                        <p role="alert" class="pt-1 text-xs text-danger">{{ $redirect->last_error }}</p>
                                    @endif
                                </td>
                                <td class="{{ $cell }} max-w-md truncate text-muted" title="{{ $redirect->target }}">{{ $redirect->isGone() ? 'Gone' : $redirect->target }}</td>
                                <td class="{{ $cell }} whitespace-nowrap"><x-mainstay::status-chip :status="(string) $redirect->status" ground="surface" /></td>
                                <td class="{{ $cell }} whitespace-nowrap text-muted">{{ $origins[$redirect->origin] ?? $redirect->origin }}</td>
                                <td class="{{ $cell }} whitespace-nowrap tabular-nums text-muted">{{ number_format($redirect->hits) }}</td>
                                <td class="{{ $cell }} whitespace-nowrap text-muted"><x-mainstay::date-text :iso="$redirect->last_hit_at?->toIso8601String()" empty="Never" /></td>
                                <td class="px-3 py-3.5 align-middle">
                                    <x-mainstay::dropdown align="end" :chevron="false" trigger-class="rounded-control p-1 text-muted hover:bg-canvas hover:text-ink">
                                        <x-slot:label><x-mainstay::more-icon :title="$redirect->source" /></x-slot:label>
                                        <x-mainstay::dropdown-item as="a" :href="route('mainstay.plugins.redirects.edit', $redirect->id)">Edit</x-mainstay::dropdown-item>
                                        <form method="post" action="{{ route('mainstay.plugins.redirects.toggle', $redirect->id) }}">
                                            @csrf
                                            <x-mainstay::dropdown-item type="submit">{{ $redirect->enabled ? 'Turn off' : 'Turn on' }}</x-mainstay::dropdown-item>
                                        </form>
                                        <x-mainstay::dropdown-item danger :data-dialog-open="'delete-'.$redirect->id">Delete</x-mainstay::dropdown-item>
                                    </x-mainstay::dropdown>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <x-mainstay::pager :start="($redirects->currentPage() - 1) * $redirects->perPage()" :shown="$redirects->count()" :total="$redirects->total()" :page="$redirects->currentPage()" :page-count="max($redirects->lastPage(), 1)" :href="fn (int $to) => $redirects->url($to)" />
    </div>

    @foreach ($redirects as $redirect)
        <x-mainstay::dialog :id="'delete-'.$redirect->id" label="Delete this redirect?">
            <form method="post" action="{{ route('mainstay.plugins.redirects.destroy', $redirect->id) }}" class="space-y-4">
                @csrf
                <p>Visitors asking for <code>{{ $redirect->source }}</code> get what the site answers there instead.</p>
                <div class="flex justify-end gap-2">
                    <x-mainstay::button type="button" variant="secondary" data-dialog-close>Keep it</x-mainstay::button>
                    <x-mainstay::button type="submit" variant="danger">Delete</x-mainstay::button>
                </div>
            </form>
        </x-mainstay::dialog>
    @endforeach

    <x-mainstay::dialog id="delete-ticked" label="Delete the ticked redirects?">
        <div class="space-y-4">
            <p>Visitors asking for their addresses get what the site answers there instead.</p>
            <div class="flex justify-end gap-2">
                <x-mainstay::button type="button" variant="secondary" data-dialog-close>Keep them</x-mainstay::button>
                <x-mainstay::button type="submit" variant="danger" form="bulk-form" name="action" value="delete">Delete</x-mainstay::button>
            </div>
        </div>
    </x-mainstay::dialog>
</x-mainstay::admin>
