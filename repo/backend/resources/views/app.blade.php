<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Admissions System') }}</title>
    @php
        $manifest = json_decode(file_get_contents(public_path('build/.vite/manifest.json')), true);
        $cssEntry = $manifest['src/css/app.css'] ?? null;
        $jsEntry = $manifest['src/app.js'] ?? null;
    @endphp
    @if($cssEntry)
        <link rel="stylesheet" href="/build/{{ $cssEntry['file'] }}">
    @endif
</head>
<body>
    <div id="app"></div>
    @if($jsEntry)
        @if(!empty($jsEntry['css']))
            @foreach($jsEntry['css'] as $css)
                <link rel="stylesheet" href="/build/{{ $css }}">
            @endforeach
        @endif
        <script type="module" src="/build/{{ $jsEntry['file'] }}"></script>
    @endif
</body>
</html>
