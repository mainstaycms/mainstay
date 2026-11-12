@props(['title'])

@php
    $screens = \Mainstay\Navigation::settingsScreens();
    $here = '/'.ltrim(request()->path(), '/');
    $current = collect($screens)->sortByDesc(fn (array $screen) => strlen($screen['href']))->first(fn (array $screen) => $here === $screen['href'] || str_starts_with($here, $screen['href'].'/'))['href'] ?? null;
@endphp

{{--
 | A screen inside Settings: the admin's layout with Settings' own navigation
 | beside it -- General, and the screen each plugin has made of its own -- as
 | a list on the left, and a row to scroll along on a phone. `actions` is the
 | admin layout's, passed through.
--}}
<x-mainstay::admin :title="$title" :breadcrumb="[['label' => 'Settings', 'href' => route('mainstay.settings')], ['label' => $title]]">
    @isset($actions)
        <x-slot:actions>{{ $actions }}</x-slot:actions>
    @endisset

    <div class="flex h-full flex-col md:flex-row">
        <nav aria-label="Settings" class="shrink-0 border-b border-border md:w-48 md:border-b-0 md:border-r">
            <ul class="flex gap-1 overflow-x-auto px-3 py-2 md:flex-col md:px-2 md:py-3">
                @foreach ($screens as $screen)
                    <li class="shrink-0">
                        <a href="{{ $screen['href'] }}" @if ($screen['href'] === $current) aria-current="page" @endif class="block whitespace-nowrap rounded-control px-2 py-1.5 text-sm {{ $screen['href'] === $current ? 'bg-surface font-medium text-ink' : 'text-muted hover:bg-surface hover:text-ink' }}">{{ $screen['label'] }}</a>
                    </li>
                @endforeach
            </ul>
        </nav>

        <div class="min-w-0 flex-1 overflow-y-auto">{{ $slot }}</div>
    </div>
</x-mainstay::admin>
