<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Desa {{ $village->name ?: 'Contoh' }} — Sistem Informasi Desa</title>
    <meta name="description" content="Situs resmi dan layanan administrasi Desa {{ $village->name }}.">

    @include('partials.tema')

    {{-- Satu sumber gaya dengan panel administrasi (design system di
         resources/css/app.css), supaya portal publik tidak menyimpang. --}}
    @vite(['resources/css/app.css'])
</head>
<body class="h-full antialiased">
<main class="flex min-h-full items-center justify-center p-6">
    <div class="w-full max-w-xl space-y-6">
        <div class="text-center">
            <div class="text-5xl" aria-hidden="true">🏛</div>
            <h1 class="mt-3 text-2xl font-semibold text-content">Desa {{ $village->name ?: 'Contoh' }}</h1>
            @if ($village->district)
                <p class="mt-1 text-sm text-content-secondary">Kec. {{ $village->district }}, Kab. {{ $village->regency }}</p>
            @endif
        </div>

        <x-alert type="info" title="Portal publik dibangun pada Sprint 6">
            Halaman ini masih sementara. Portal publik — profil desa, berita,
            pengumuman, standar pelayanan, dan statistik — dijadwalkan pada Sprint 6
            (docs/11-roadmap-estimasi.md §2).
        </x-alert>

        <div class="text-center">
            <a href="{{ route('login') }}"
               class="touch-target inline-flex items-center justify-center rounded-lg bg-accent px-5 py-2.5 font-medium text-content-inverse transition-colors hover:bg-accent-hover">
                Masuk ke sistem
            </a>
        </div>
    </div>
</main>
</body>
</html>
