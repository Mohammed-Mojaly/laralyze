@props(['title' => null])
@inject('assets', 'Laralyze\Dashboard\Assets')
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ? $title.' · Laralyze' : 'Laralyze' }}</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='7' fill='%2315181c'/%3E%3Cpath d='M6 16c3-5 6.5-7.5 10-7.5S23 11 26 16c-3 5-6.5 7.5-10 7.5S9 21 6 16Z' fill='none' stroke='%23fff' stroke-width='2.2'/%3E%3Ccircle cx='16' cy='16' r='3.2' fill='%23fff'/%3E%3C/svg%3E">

    {{ $assets->styles() }}
    @livewireStyles

    {{ $assets->scripts() }}
    @livewireScriptConfig(['nonce' => $assets->nonce()])
</head>
<body class="rq">
    {{ $slot }}
</body>
</html>
