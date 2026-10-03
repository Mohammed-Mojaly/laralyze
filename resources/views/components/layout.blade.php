@props(['title' => null])
@inject('assets', 'MohammedMojaly\Laralyze\Dashboard\Assets')
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ? $title.' · Laralyze' : 'Laralyze' }}</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='7' fill='%230e7c86'/%3E%3Crect x='7' y='6' width='4.5' height='20' rx='2.25' fill='%23fff'/%3E%3Crect x='13.5' y='9.5' width='9' height='4' rx='2' fill='%23fff' opacity='.85'/%3E%3Crect x='16.5' y='15' width='6.5' height='4' rx='2' fill='%23fff' opacity='.55'/%3E%3Crect x='7' y='21.5' width='18' height='4.5' rx='2.25' fill='%23fff'/%3E%3C/svg%3E">

    {{ $assets->styles() }}
    @livewireStyles

    {{ $assets->scripts() }}
    @livewireScriptConfig(['nonce' => $assets->nonce()])
</head>
<body class="lz-root">
    {{ $slot }}
</body>
</html>
