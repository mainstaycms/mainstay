{{-- The counts At a glance draws, each a link to its list. --}}
@if ($counts === [])
    <p class="text-sm text-muted">There is nothing here you may read yet.</p>
@else
    <ul class="grid grid-cols-2 gap-4">
        @foreach ($counts as $count)
            <li>
                <a href="{{ $count['href'] }}" class="block rounded-control focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">
                    <span class="block text-2xl font-medium">{{ $count['count'] }}</span>
                    <span class="block text-sm text-muted">{{ $count['label'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>
@endif
