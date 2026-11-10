{{-- Each part and whether it has been published. --}}
<ul class="space-y-2 text-sm">
    @foreach ($parts as $part)
        <li class="flex items-baseline justify-between gap-2">
            <a href="{{ $part['href'] }}" class="min-w-0 truncate font-medium text-accent hover:underline">{{ $part['label'] }}</a>
            <span class="shrink-0 text-xs text-muted">{{ $part['status'] }}</span>
        </li>
    @endforeach
</ul>
