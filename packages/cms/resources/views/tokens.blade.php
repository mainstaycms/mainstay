@php
    $cell = 'px-4 py-3 align-middle';
    $secret = session(\Mainstay\Http\TokenController::SECRET);
    $held = old('capabilities', []);
@endphp

{{--
 | The signed-in account's API tokens: each one's name, what it holds, when
 | it was made and last used, and Revoke; then a new one, named and given
 | capabilities from the grid, never more than the account holds. A new
 | token's secret is shown above them once, as the page after making it.
--}}
<x-mainstay::admin title="API tokens" :breadcrumb="[['label' => 'Account settings', 'href' => route('mainstay.account')], ['label' => 'API tokens']]">
    <x-slot:actions>
        <x-mainstay::button type="submit" form="token-form">Make token</x-mainstay::button>
    </x-slot:actions>

    <div class="h-full overflow-y-auto">
        <div class="mx-auto max-w-4xl space-y-6 px-6 py-8">
            <x-mainstay::notices :messages="$errors->all()" />

            @if ($secret)
                <x-mainstay::field-group label="Your new token">
                    <x-mainstay::field label="Token" for="token-secret" hint="Copy it now: it is not shown again. A program sends it as Authorization: Bearer and the token.">
                        <x-mainstay::input id="token-secret" readonly class="font-mono" :value="$secret" aria-describedby="token-secret-hint" />
                    </x-mainstay::field>
                </x-mainstay::field-group>
            @endif

            <section class="rounded-control border border-border bg-surface">
                <h2 class="border-b border-border px-4 py-2.5 text-xs font-medium text-muted">Tokens</h2>
                @if ($tokens->isEmpty())
                    <p class="px-4 py-3.5 text-sm text-muted">None yet. A token lets a program, such as a build or an MCP client, read and write content as you.</p>
                @else
                    <div class="overflow-x-auto">
                        <table aria-label="Tokens" class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs text-muted">
                                    <th scope="col" class="px-4 py-2 font-medium">Name</th>
                                    <th scope="col" class="px-4 py-2 font-medium">Holds</th>
                                    <th scope="col" class="px-4 py-2 font-medium">Made</th>
                                    <th scope="col" class="px-4 py-2 font-medium">Last used</th>
                                    <th scope="col" class="px-4 py-2"><span class="sr-only">Revoke</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($tokens as $token)
                                    <tr class="border-t border-border">
                                        <th scope="row" class="{{ $cell }} text-left font-medium">{{ $token->name }}</th>
                                        <td class="{{ $cell }} text-muted">
                                            @if ($token->capabilities === ['*'])
                                                Everything you hold
                                            @elseif ($token->capabilities === [])
                                                Reading only
                                            @else
                                                <span class="font-mono text-xs">{{ implode(', ', $token->capabilities) }}</span>
                                            @endif
                                        </td>
                                        <td class="{{ $cell }} whitespace-nowrap text-muted"><x-mainstay::date-text :iso="$token->created_at?->toIso8601String()" /></td>
                                        <td class="{{ $cell }} whitespace-nowrap text-muted"><x-mainstay::date-text :iso="$token->last_used_at?->toIso8601String()" empty="Never" /></td>
                                        <td class="{{ $cell }} text-right">
                                            <x-mainstay::button type="button" variant="secondary" data-dialog-open="revoke-token-{{ $token->id }}">Revoke</x-mainstay::button>
                                            <x-mainstay::dialog :id="'revoke-token-'.$token->id" label="Revoke this token?">
                                                <form method="post" action="{{ route('mainstay.tokens.destroy', $token->id) }}" class="space-y-4 text-left">
                                                    @csrf
                                                    <p>{{ $token->name }} stops working at once, for whatever uses it. This cannot be undone.</p>
                                                    <div class="flex justify-end gap-2">
                                                        <x-mainstay::button type="button" variant="secondary" data-dialog-close>Keep it</x-mainstay::button>
                                                        <x-mainstay::button type="submit" variant="danger">Revoke</x-mainstay::button>
                                                    </div>
                                                </form>
                                            </x-mainstay::dialog>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <form id="token-form" method="post" action="{{ route('mainstay.tokens.store') }}" class="space-y-6">
                @csrf
                <x-mainstay::field-group label="New token">
                    <x-mainstay::field label="Name" for="token-name" hint="What it is for, as you will see it listed.">
                        <x-mainstay::input id="token-name" name="name" maxlength="255" required :value="old('name')" aria-describedby="token-name-hint" />
                    </x-mainstay::field>
                    <div class="flex items-center gap-2 px-4 py-3.5">
                        <x-mainstay::checkbox id="token-everything" name="capabilities[]" value="*" label="Everything" :checked="in_array('*', $held, true)" :disabled="! $covered('*')" />
                        <label for="token-everything" class="text-sm">Everything, whatever you hold now and later</label>
                    </div>
                    <p class="px-4 pb-3.5 text-xs text-muted">With nothing ticked, it reads what you may read and writes nothing.</p>
                </x-mainstay::field-group>

                <x-mainstay::capability-grid :sections="$sections" :held="$held" :covered="$covered" />
            </form>
        </div>
    </div>
</x-mainstay::admin>
