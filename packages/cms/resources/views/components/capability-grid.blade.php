@props(['sections', 'held', 'covered'])

{{--
 | Capabilities as a grid, a section per shape of type and a column per verb,
 | each box posting capabilities[]: a role's, and a token's. A box the user
 | could not hand out, `$covered` says, is disabled.
--}}
@foreach ($sections as $section)
    <section class="rounded-control border border-border bg-surface">
        <h2 class="border-b border-border px-4 py-2.5 text-xs font-medium text-muted">{{ $section['label'] }}</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-muted">
                        <th scope="col" class="px-4 py-2 font-medium"><span class="sr-only">Type</span></th>
                        @foreach ($section['verbs'] as $verb)
                            <th scope="col" class="px-3 py-2 text-center font-medium">{{ $verb }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($section['rows'] as $row)
                        <tr class="border-t border-border">
                            <th scope="row" class="px-4 py-2 text-left font-normal">{{ $row['label'] }}</th>
                            @foreach ($row['names'] as $i => $name)
                                <td class="px-3 py-2 text-center">
                                    <x-mainstay::checkbox name="capabilities[]" :value="$name" :label="$row['label'].': '.$section['verbs'][$i]" :title="$name" :checked="in_array($name, $held, true)" :disabled="! $covered($name)" />
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endforeach
