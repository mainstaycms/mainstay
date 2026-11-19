{{--
 | The plugin's sections as the tabs of one screen, but links: each is its
 | own page, so a list's filters and a form's errors stay where they belong
 | and nothing needs a script. `$current` is the section shown.
--}}
<nav aria-label="Redirects sections" class="flex h-14 shrink-0 items-center gap-5 border-b border-border px-6">
    @foreach (['redirects' => ['Redirects', route('mainstay.plugins.redirects.index')], 'misses' => ['Misses'.($missCount > 0 ? " ({$missCount})" : ''), route('mainstay.plugins.redirects.misses')]] as $section => [$label, $href])
        <a
            href="{{ $href }}"
            @if ($current === $section) aria-current="page" @endif
            class="-mb-px flex h-full items-center border-y-2 border-transparent text-sm text-muted hover:text-ink aria-[current=page]:border-b-accent aria-[current=page]:font-medium aria-[current=page]:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
        >{{ $label }}</a>
    @endforeach
</nav>
