@props(['entry' => null, 'title' => null, 'description' => null])

{{--
 | The page's search and social tags, for the host's layout to call inside
 | <head>: `<x-seo::head :entry="$entry" />`. A page that is no entry passes
 | its `title` and `description`. Everything is escaped as an attribute or as
 | JSON that cannot close its own script.
--}}
@php($tags = app(\Mainstay\Seo\Head::class)->build($entry, $title, $description))
<title>{{ $tags['title'] }}</title>
@if ($tags['description'])
<meta name="description" content="{{ $tags['description'] }}">
@endif
@if ($tags['robots'])
<meta name="robots" content="{{ $tags['robots'] }}">
@endif
<link rel="canonical" href="{{ $tags['canonical'] }}">
@foreach ($tags['alternates'] as [$language, $href])
<link rel="alternate" hreflang="{{ $language }}" href="{{ $href }}">
@endforeach
@foreach ($tags['meta'] as [$attribute, $key, $content])
<meta {{ $attribute }}="{{ $key }}" content="{{ $content }}">
@endforeach
@if ($tags['graph'])
<script type="application/ld+json">{!! $tags['graph'] !!}</script>
@endif
