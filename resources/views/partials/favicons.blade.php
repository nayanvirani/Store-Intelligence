{{-- Favicons with a version from the icon itself, so browsers fetch a new icon as soon as it changes. --}}
@php($dir = $dir ?? '')
@php($v = substr(md5_file(public_path(($dir ?: '') . 'favicon.svg')), 0, 8))
<link rel="icon" href="/{{ $dir }}favicon.svg?v={{ $v }}" type="image/svg+xml">
<link rel="icon" href="/{{ $dir ?: 'brand/' }}favicon-32.png?v={{ $v }}" sizes="32x32" type="image/png">
<link rel="apple-touch-icon" href="/{{ $dir ?: 'brand/' }}apple-touch-icon.png?v={{ $v }}">
@unless ($dir)<link rel="shortcut icon" href="/favicon.ico?v={{ $v }}">@endunless
