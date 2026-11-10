{{--
 | The admin's front door: a card for each widget the user has on, and
 | Screen options above them, where they choose which. A widget that spans
 | two columns takes the whole row where there are two.
--}}
<x-mainstay::admin :breadcrumb="[['label' => 'Dashboard']]">
    <div class="h-full overflow-y-auto">
        <div class="mx-auto max-w-5xl space-y-6 px-6 py-8">
            <x-mainstay::notices />

            <details class="ml-auto w-full max-w-sm rounded-control border border-border bg-surface">
                <summary class="cursor-pointer px-3 py-1.5 text-sm text-muted hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">Screen options</summary>
                <form method="post" action="{{ route('mainstay.dashboard.screen') }}" class="space-y-3 border-t border-border px-3 py-3">
                    @csrf
                    <ul class="space-y-2">
                        @foreach ($available as $widget)
                            <li class="flex items-center gap-2 text-sm">
                                <x-mainstay::checkbox id="widget-{{ $widget::handle() }}" name="widgets[]" :value="$widget::handle()" :label="$widget->title()" :checked="in_array($widget, $shown, true)" />
                                <label for="widget-{{ $widget::handle() }}">{{ $widget->title() }}</label>
                            </li>
                        @endforeach
                    </ul>
                    <x-mainstay::button type="submit">Apply</x-mainstay::button>
                </form>
            </details>

            @if ($shown === [])
                <p class="text-sm text-muted">No widgets are on. Switch some on in Screen options.</p>
            @else
                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    @foreach ($shown as $widget)
                        <section aria-labelledby="widget-{{ $widget::handle() }}-title" class="rounded-control border border-border bg-surface @if ($widget->columns() === 2) md:col-span-2 @endif">
                            <h2 id="widget-{{ $widget::handle() }}-title" class="border-b border-border px-4 py-2.5 text-xs font-medium text-muted">{{ $widget->title() }}</h2>
                            <div class="px-4 py-3">
                                @include($widget->view(), $widget->data($user))
                            </div>
                        </section>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-mainstay::admin>
