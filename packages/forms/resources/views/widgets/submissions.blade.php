{{-- The total and the newest submissions, each a link to its read-only page. --}}
<a href="{{ $all }}" class="block rounded-control focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">
    <span class="block text-2xl font-medium">{{ $total }}</span>
    <span class="block text-sm text-muted">{{ $total === 1 ? 'submission' : 'submissions' }}</span>
</a>
@if ($newest !== [])
    <ul class="mt-2 space-y-2 text-sm">
        @foreach ($newest as $submission)
            <li class="min-w-0">
                <a href="{{ $submission['href'] }}" class="block truncate font-medium text-accent hover:underline">{{ $submission['form'] }}</a>
                <span class="block truncate text-xs text-muted">{{ $submission['at']->diffForHumans() }}@if ($submission['answer'] !== '') · {{ $submission['answer'] }}@endif</span>
            </li>
        @endforeach
    </ul>
@endif
@if ($forms !== null)
    <p class="mt-2 text-sm"><a href="{{ $forms }}" class="text-accent hover:underline">Forms</a></p>
@endif
