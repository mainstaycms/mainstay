@props(['title' => null, 'breadcrumb' => [], 'actions' => null, 'options' => null])

@php
    $user = \Mainstay\Auth\Gate::user();
    $assets = collect(\Mainstay\Facades\Mainstay::assets())->groupBy(fn (string $path) => str_ends_with($path, '.css') ? 'styles' : 'scripts');
    $site = array_values(\Mainstay\Facades\Mainstay::locales())[0];
@endphp

{{-- Every signed-in screen: the document, the shell, the bar and the
     navigation, with the screen's own title, trail and actions. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}Mainstay</title>
    <link rel="stylesheet" href="{{ asset('vendor/mainstay/mainstay.css') }}?v={{ \Mainstay\Mainstay::VERSION }}">
    @foreach ($assets['styles'] ?? [] as $path)
        <link rel="stylesheet" href="{{ asset($path) }}?v={{ \Mainstay\Mainstay::VERSION }}">
    @endforeach
</head>
{{-- Marked once a save has written what a form posted, so the browser
     drops its copy of that form: see form-copy.ts. --}}
<body class="h-full" @if (session(\Mainstay\Http\Form::SAVED)) data-saved @endif>
    <x-mainstay::shell :breadcrumb="$breadcrumb" :actions="$actions">
        <x-slot:bar>
            <x-mainstay::top-bar
                :commands="\Mainstay\Navigation::commands()"
                :website="$site['host'] === null ? url($site['prefix'] ?: '/') : $site['origin'].'/'"
                :name="$user->name"
                :email="$user->email"
            >
                {{-- What a plugin puts on every screen, then Screen options, which
                     hold whatever the current screen lets a user choose; a screen
                     with none gets no button. --}}
                <x-slot:tools>
                    @foreach (\Mainstay\Navigation::tools() as $tool)
                        @include($tool['view'], ['label' => $tool['label']])
                    @endforeach
                    @if ($options)
                        <button type="button" data-dialog-open="screen-options" class="flex h-6 items-center whitespace-nowrap rounded-control px-1.5 text-xs text-muted hover:bg-canvas hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">
                            {{-- Icon only below sm, so the user menu keeps its room in a narrow bar. --}}
                            <x-mainstay::icon class="size-4 sm:hidden"><path d="M2 4.5h12M2 11.5h12" /><circle cx="6" cy="4.5" r="1.5" fill="var(--color-surface)" /><circle cx="10.5" cy="11.5" r="1.5" fill="var(--color-surface)" /></x-mainstay::icon>
                            <span class="max-sm:sr-only">Screen options</span>
                        </button>
                    @endif
                </x-slot:tools>
                <x-mainstay::dropdown-item as="a" :href="route('mainstay.account')">Account settings</x-mainstay::dropdown-item>
                <x-mainstay::dropdown-item as="a" :href="route('mainstay.tokens')">API tokens</x-mainstay::dropdown-item>
                <form method="POST" action="{{ route('mainstay.logout') }}" data-sign-out>
                    @csrf
                    <x-mainstay::dropdown-item type="submit">Sign out</x-mainstay::dropdown-item>
                </form>
            </x-mainstay::top-bar>
        </x-slot:bar>

        <x-slot:navigation>
            {{-- The request URI, not getPathInfo(): the hrefs come from route()
                 and carry the application's base path, so a `current` with it
                 stripped would match nothing on an app served from a
                 subdirectory. --}}
            <x-mainstay::sidebar :sections="\Mainstay\Navigation::sections()" :current="request()->getRequestUri()" />
        </x-slot:navigation>

        {{ $slot }}
    </x-mainstay::shell>

    @if ($options)
        <x-mainstay::dialog id="screen-options" label="Screen options" wide plain class="rounded-none!">{{ $options }}</x-mainstay::dialog>
    @endif

    {{-- Dialogs a field draws, and those of a plugin's tools, out here where no form holds them, so each
         can have a form of its own. --}}
    @stack('dialogs')

    <script type="module" src="{{ asset('vendor/mainstay/mainstay.js') }}?v={{ \Mainstay\Mainstay::VERSION }}"></script>
    {{-- A plugin's behaviour, after the admin's own. --}}
    @foreach ($assets['scripts'] ?? [] as $path)
        <script type="module" src="{{ asset($path) }}?v={{ \Mainstay\Mainstay::VERSION }}"></script>
    @endforeach
</body>
</html>
