@php
    $exists = $redirect->exists;
    $action = $exists ? route('mainstay.plugins.redirects.update', $redirect->id) : route('mainstay.plugins.redirects.store');
    $labels = [301 => '301 Moved permanently', 302 => '302 Found', 307 => '307 Temporary redirect', 308 => '308 Permanent redirect', 410 => '410 Gone: nothing is here any more'];
    $value = fn (string $field) => old($field, $redirect->{$field});
    $flag = fn (string $field) => filter_var(old($field, $redirect->{$field}), FILTER_VALIDATE_BOOL);
    $error = fn (string $field) => $errors->get($field);
    $describedby = fn (string $field, bool $hint = true) => $errors->has($field) ? $field.'-error' : ($hint ? $field.'-hint' : null);
@endphp

{{--
 | One redirect: where it applies, where it goes and how. The same form makes
 | and changes one. Everything it posts is checked on the server, which also
 | refuses a pattern that does not compile or backtracks without end and a
 | redirect that would lead round in a circle.
--}}
<x-mainstay::admin :title="$exists ? 'Edit redirect' : 'New redirect'" :breadcrumb="[['label' => 'Redirects', 'href' => route('mainstay.plugins.redirects.index')], ['label' => $exists ? 'Edit' : 'New']]">
    <x-slot:actions>
        <a href="{{ route('mainstay.plugins.redirects.index') }}" class="rounded-control px-2 py-1 text-sm text-muted hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">Cancel</a>
        <x-mainstay::button type="submit" form="redirect-form">Save</x-mainstay::button>
    </x-slot:actions>

    <div class="flex h-full min-h-0 flex-col">
        @include('redirects::partials.nav', ['current' => 'redirects'])

        <form id="redirect-form" method="post" action="{{ $action }}" class="min-h-0 flex-1 overflow-y-auto">
            @csrf
            <div class="space-y-6 px-6 py-8">
                <x-mainstay::notices />

                @if ($exists && $redirect->isAutomatic())
                    <p class="rounded-control border border-border bg-surface px-3 py-2 text-sm">Made for you when {{ $redirect->origin === 'moved' ? 'a page moved' : 'a page was trashed' }}. Saving it here makes it yours: restoring or moving the page again will leave it alone.</p>
                @endif

                @if ($exists && $redirect->last_error)
                    <p role="alert" class="rounded-control border border-danger/40 bg-surface px-3 py-2 text-sm text-danger">{{ $redirect->last_error }}</p>
                @endif

                <x-mainstay::field-group label="Where it applies">
                    <x-mainstay::field label="Kind" for="match_type" hint="An address is one page. A pattern is a PCRE regular expression over the whole path, written without delimiters, for moving many pages at once.">
                        <x-mainstay::select id="match_type" name="match_type" aria-describedby="match_type-hint">
                            <option value="exact" @selected($value('match_type') === 'exact')>Address</option>
                            <option value="regex" @selected($value('match_type') === 'regex')>Pattern (regular expression)</option>
                        </x-mainstay::select>
                        <x-mainstay::messages id="match_type-error" :messages="$error('match_type')" />
                    </x-mainstay::field>

                    <x-mainstay::field label="From" for="source" hint="An address: /old-page, with or without a trailing slash and in any case. A pattern: ^/old-blog/(.*)$ matches the whole path; groups are used in the target as $1 or ${name}.">
                        <x-mainstay::input id="source" name="source" :value="$value('source')" required maxlength="500" autocomplete="off" spellcheck="false" placeholder="/old-page" :aria-invalid="$errors->has('source') ? 'true' : null" :aria-describedby="$describedby('source')" />
                        <x-mainstay::messages id="source-error" :messages="$error('source')" />
                    </x-mainstay::field>

                    <x-mainstay::field label="Order of patterns" for="priority" hint="Lower numbers are tried first, and the first pattern to match wins. Addresses are always tried before patterns.">
                        <x-mainstay::input id="priority" name="priority" type="number" min="0" max="1000000" :value="$value('priority')" aria-describedby="priority-hint" />
                        <x-mainstay::messages id="priority-error" :messages="$error('priority')" />
                    </x-mainstay::field>
                </x-mainstay::field-group>

                <x-mainstay::field-group label="Where it goes">
                    <x-mainstay::field label="Status" for="status">
                        <x-mainstay::select id="status" name="status" :aria-invalid="$errors->has('status') ? 'true' : null" :aria-describedby="$describedby('status', false)">
                            @foreach ($statuses as $status)
                                <option value="{{ $status }}" @selected((int) $value('status') === $status)>{{ $labels[$status] }}</option>
                            @endforeach
                        </x-mainstay::select>
                        <x-mainstay::messages id="status-error" :messages="$error('status')" />
                    </x-mainstay::field>

                    <x-mainstay::field label="To" for="target" hint="A path on this site, /new-page, or a full address. Not used for 410. A pattern's target may use $1 to $9 and ${name}.">
                        <x-mainstay::input id="target" name="target" :value="$value('target')" maxlength="2000" autocomplete="off" spellcheck="false" placeholder="/new-page" :aria-invalid="$errors->has('target') ? 'true' : null" :aria-describedby="$describedby('target')" />
                        <x-mainstay::messages id="target-error" :messages="$error('target')" />
                    </x-mainstay::field>

                    <div class="border-b border-border px-4 py-3.5">
                        <label for="preserve_query" class="flex cursor-pointer items-start justify-between gap-3">
                            <span class="min-w-0">
                                <span class="block text-sm font-medium text-ink">Keep the query string</span>
                                <span class="mt-1 block text-xs text-muted">A visitor's <code>?utm_source=mail</code> is added to the target.</span>
                            </span>
                            <input type="hidden" name="preserve_query" value="0">
                            <x-mainstay::switch id="preserve_query" name="preserve_query" value="1" label="Keep the query string" :checked="$flag('preserve_query')" />
                        </label>
                    </div>

                    <div class="border-b border-border px-4 py-3.5">
                        <label for="enabled" class="flex cursor-pointer items-start justify-between gap-3">
                            <span class="min-w-0">
                                <span class="block text-sm font-medium text-ink">On</span>
                                <span class="mt-1 block text-xs text-muted">A redirect that is off is kept and not applied.</span>
                            </span>
                            <input type="hidden" name="enabled" value="0">
                            <x-mainstay::switch id="enabled" name="enabled" value="1" label="On" :checked="$flag('enabled')" />
                        </label>
                    </div>
                </x-mainstay::field-group>

                <x-mainstay::field-group label="Notes">
                    <x-mainstay::field label="Note" for="note" hint="For whoever reads the list later: why this one is here.">
                        <x-mainstay::input id="note" name="note" :value="$value('note')" maxlength="500" aria-describedby="note-hint" />
                        <x-mainstay::messages id="note-error" :messages="$error('note')" />
                    </x-mainstay::field>
                </x-mainstay::field-group>
            </div>
        </form>
    </div>
</x-mainstay::admin>
