<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
@foreach ($pages as $page)
    <url>
        <loc>{{ $page['loc'] }}</loc>
@if ($page['modified'])
        <lastmod>{{ $page['modified'] }}</lastmod>
@endif
@foreach ($page['alternates'] as [$language, $href])
        <xhtml:link rel="alternate" hreflang="{{ $language }}" href="{{ $href }}"/>
@endforeach
    </url>
@endforeach
</urlset>
