{{-- Providers with a key, and the provider each kind of work goes to. --}}
<a href="{{ $href }}" class="block rounded-control focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">
    <span class="block text-2xl font-medium">{{ $connected }}</span>
    <span class="block text-sm text-muted">of {{ $providers }} {{ $providers === 1 ? 'provider has' : 'providers have' }} a key</span>
</a>
@if ($defaults !== [])
    <ul class="mt-2 space-y-2 text-sm">
        @foreach ($defaults as $default)
            <li class="flex items-baseline justify-between gap-2">
                <span class="text-muted">{{ $default['use'] }}</span>
                <span class="min-w-0 truncate">{{ $default['provider'] }}</span>
            </li>
        @endforeach
    </ul>
@endif
