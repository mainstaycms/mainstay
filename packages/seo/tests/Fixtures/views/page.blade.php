<!doctype html>
<html lang="{{ $entry->locale }}">
<head><x-seo::head :entry="$entry" /></head>
<body>
<header><h1>The site</h1><nav><a href="/menu">Menu</a></nav></header>
<main>
<h1>{{ $entry->title }}</h1>
<p>First paragraph about {{ $entry->title }}.</p>
<h2>Second</h2>
<p>More text with <a href="/blog/other">an internal link</a> and <a href="https://example.org/x">an outbound one</a>.</p>
<img src="/a.jpg" alt="A picture"><img src="/b.jpg">
</main>
<footer><p>Footer text</p><a href="/legal">Legal</a></footer>
</body>
</html>
