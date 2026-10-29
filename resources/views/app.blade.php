<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Panel administrasi tidak boleh terindeks mesin pencari. --}}
    <meta name="robots" content="noindex, nofollow">

    <title inertia>{{ config('app.name') }}</title>

    @include('partials.tema')
    @include('partials.pwa')

    @vite(['resources/css/app.css', 'resources/js/app.ts'])
    @inertiaHead
</head>
<body class="h-full antialiased">
    @inertia
</body>
</html>
