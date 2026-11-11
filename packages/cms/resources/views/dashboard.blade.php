{{--
 | The admin's front door: a card for each widget the user has on, and
 | Screen options, which the layout opens as a dialog from the top bar, where
 | they choose which. The cards flow down CSS columns, so each is as tall as
 | its content, and are ruled like the list screens: hairlines on the page
 | background, no floating cards and no header strip.
--}}
<x-mainstay::admin :breadcrumb="[['label' => 'Dashboard']]">
    <x-slot:options>
        <form method="post" action="{{ route('mainstay.dashboard.screen') }}">
            @csrf
            <ul class="grid border-t border-border sm:grid-cols-2 [&>li]:border-b [&>li]:border-border sm:[&>li:nth-child(odd)]:border-r">
                @foreach ($available as $widget)
                    <li>
                        <label for="widget-{{ $widget::handle() }}" class="flex h-full cursor-pointer items-start justify-between gap-3 px-5 py-4 hover:bg-surface">
                            <span class="min-w-0">
                                <span class="block text-sm font-medium text-ink">{{ $widget->title() }}</span>
                                @if ($widget->description() !== '')
                                    <span class="mt-1 block text-xs text-muted">{{ $widget->description() }}</span>
                                @endif
                            </span>
                            <x-mainstay::switch id="widget-{{ $widget::handle() }}" name="widgets[]" :value="$widget::handle()" :label="$widget->title()" :checked="in_array($widget, $shown, true)" />
                        </label>
                    </li>
                @endforeach
            </ul>
            <div class="px-5 py-4">
                <x-mainstay::button type="submit">Apply</x-mainstay::button>
            </div>
        </form>
    </x-slot:options>

    <div class="h-full overflow-y-auto">
        <div>
            <div class="px-5 pt-5 empty:hidden"><x-mainstay::notices /></div>

            @if ($shown === [])
                <p class="px-5 py-5 text-sm text-muted">No widgets are on. Switch some on in Screen options.</p>
            @else
                <div class="columns-1 gap-0 md:columns-2 xl:columns-3 md:[column-rule:1px_solid_var(--color-border)]">
                    @foreach ($shown as $widget)
                        <section aria-labelledby="widget-{{ $widget::handle() }}-title" class="break-inside-avoid border-b border-border">
                            <div class="px-5 py-4">
                                <h2 id="widget-{{ $widget::handle() }}-title" class="mb-3 text-sm font-medium text-ink">{{ $widget->title() }}</h2>
                                @include($widget->view(), $widget->data($user))
                            </div>
                        </section>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-mainstay::admin>
