{{--
 | The Content tab's page for a global edited as one, which has no view of
 | its own to be drawn in: its content alone, in the site's editor styles and
 | the class its text sits in, as Mainstay::editorStyles() names them. The
 | content is the only thing in <main>, which is what makes it editable here.
--}}
<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    @foreach ($style['stylesheets'] ?? [] as $stylesheet)
        <link rel="stylesheet" href="{{ $stylesheet }}">
    @endforeach
</head>
<body>
    <main class="{{ $style['class'] ?? '' }}">{{ $content }}</main>
</body>
</html>
