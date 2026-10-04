<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Masuk') — {{ $village->name ? 'Desa '.$village->name : config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/sid.css') }}">
</head>
<body>
<main class="sid-auth">
    <div class="sid-auth__header">
        <div class="sid-auth__crest" aria-hidden="true">🏛</div>
        <h1 class="sid-mb-0">{{ $village->name ? 'Desa '.$village->name : config('app.name') }}</h1>
        @if ($village->district)
            <p class="sid-muted sid-text-sm sid-mb-0">
                Kec. {{ $village->district }}, Kab. {{ $village->regency }}
            </p>
        @endif
    </div>

    @if (session('status'))
        <div style="width:100%;max-width:440px">
            <x-alert type="success">{{ session('status') }}</x-alert>
        </div>
    @endif
    @if (session('warning'))
        <div style="width:100%;max-width:440px">
            <x-alert type="warning">{{ session('warning') }}</x-alert>
        </div>
    @endif

    @yield('content')

    <p class="sid-muted sid-text-sm sid-center sid-mt-4 sid-mb-0">
        Sistem Informasi Desa &middot;
        <a href="{{ route('home') }}">Kembali ke halaman utama</a>
    </p>
</main>
</body>
</html>
