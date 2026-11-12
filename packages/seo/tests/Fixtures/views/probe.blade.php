<main>
<h1>{{ $entry->title }}</h1>
<p>{{ auth()->check() ? 'signed in' : 'visitor' }}</p>
@if (request()->has('boom'))
{{ throw new \RuntimeException('secret Mainstay\Seo\Thing') }}
@endif
</main>
