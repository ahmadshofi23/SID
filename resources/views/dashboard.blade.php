@extends('layouts.app')
@section('title', 'Dashboard')

@section('content')
<h1>Dashboard</h1>
<p class="sid-muted">
    Selamat datang, {{ auth()->user()->name }}.
</p>

<x-alert type="info" title="Sprint 1 — Fondasi">
    Fondasi sistem sudah terpasang: autentikasi, verifikasi dua langkah, hak akses
    berjenjang, pembatasan wilayah &amp; keluarga, serta log aktivitas.
    Modul kependudukan dan persuratan dibangun pada Sprint 2–5.
</x-alert>

<div class="sid-card">
    <h2>Hak akses Anda</h2>
    <p class="sid-text-sm sid-muted">
        Daftar ini berasal dari peran yang diberikan Admin Desa. Menu yang tidak
        Anda miliki izinnya tidak akan ditampilkan — dan tetap ditolak server
        bila diakses langsung lewat URL.
    </p>

    @php $scope = app(\App\Auth\AccessScope::class); @endphp

    <p class="sid-text-sm">
        <strong>Peran:</strong>
        @foreach (auth()->user()->roles as $role)
            <span class="sid-badge">{{ $role->name }}</span>
        @endforeach
    </p>

    <p class="sid-text-sm">
        <strong>Lingkup wilayah:</strong>
        @if ($scope->isRegionLimited())
            {{ $scope->regionPaths === [] ? 'Belum ada wilayah ditugaskan — Anda belum dapat melihat data penduduk. Hubungi Admin Desa.' : 'Dibatasi pada wilayah yang ditugaskan' }}
        @else
            Seluruh desa
        @endif
    </p>

    <p class="sid-text-sm sid-mb-0">
        <strong>Jumlah izin:</strong> {{ count($scope->permissions) }}
    </p>
</div>

<div class="sid-card sid-mt-4">
    <h2>Menu tersedia</h2>
    <ul>
        @can('penduduk.lihat')
            <li><a href="{{ route('residents.index') }}">Data Penduduk</a></li>
        @endcan
        @can('audit.lihat')
            <li><a href="{{ route('audit.index') }}">Log Aktivitas</a></li>
        @endcan
    </ul>
    @if (! auth()->user()->hasTwoFactorEnabled())
        <p class="sid-mb-0">
            <a href="{{ route('two-factor.setup') }}">Aktifkan verifikasi dua langkah</a>
            — disarankan untuk semua akun.
        </p>
    @endif
</div>
@endsection
