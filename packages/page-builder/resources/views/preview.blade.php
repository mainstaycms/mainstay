{{--
 | One block on a page of its own, for the Add block dialog's preview: in
 | the site's editor styles and the class its text sits in, as
 | Mainstay::editorStyles() names them. The dialog draws it small, in a
 | frame no script runs in.
--}}
<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    @foreach ($style['stylesheets'] ?? [] as $stylesheet)
        <link rel="stylesheet" href="{{ $stylesheet }}">
    @endforeach
    <style>html { overflow: hidden }</style>
</head>
<body>
    <div class="{{ $style['class'] ?? '' }}" style="padding: 32px">{!! $html !!}</div>
</body>
</html>
