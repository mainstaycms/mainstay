{{-- The pages changed last, each a link to its form. --}}
@if ($pages === [])
    <p class="text-sm text-muted">There are no pages yet.</p>
@else
    <ul class="space-y-2 text-sm">
        @foreach ($pages as $page)
            <li class="flex items-baseline justify-between gap-2">
                <a href="{{ $page['href'] }}" class="min-w-0 truncate font-medium text-accent hover:underline">{{ $page['title'] }}</a>
                <span class="shrink-0 text-xs text-muted">{{ $page['status'] }}</span>
            </li>
        @endforeach
    </ul>
@endif
