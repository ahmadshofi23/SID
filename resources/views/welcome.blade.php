<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Desa {{ $village->name ?: 'Contoh' }} — Sistem Informasi Desa</title>
    <meta name="description" content="Situs resmi dan layanan administrasi Desa {{ $village->name }}.">
    <link rel="stylesheet" href="{{ asset('css/sid.css') }}">
</head>
<body>
<main class="sid-main">
    <div class="sid-container--narrow sid-stack">
        <div class="sid-center">
            <div class="sid-auth__crest" aria-hidden="true">🏛</div>
            <h1 class="sid-mb-0">Desa {{ $village->name ?: 'Contoh' }}</h1>
            @if ($village->district)
                <p class="sid-muted">Kec. {{ $village->district }}, Kab. {{ $village->regency }}</p>
            @endif
        </div>

        <x-alert type="info" title="Portal publik dibangun pada Sprint 6">
            Halaman ini masih sementara. Portal publik — profil desa, berita,
            pengumuman, standar pelayanan, dan statistik — dijadwalkan pada Sprint 6
            (docs/11-roadmap-estimasi.md §2).
        </x-alert>

        <div class="sid-center">
            <a href="{{ route('login') }}" class="sid-btn sid-btn--primary">
                Masuk ke sistem
            </a>
        </div>
    </div>
</main>
</body>
</html>
