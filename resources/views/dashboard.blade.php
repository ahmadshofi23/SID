@extends('layouts.admin')
@section('title', 'Dashboard')

@section('content')
@php
    $scope = app(\App\Auth\AccessScope::class);
    $residents = app(\App\Repositories\ResidentRepository::class);
    $families = app(\App\Repositories\FamilyRepository::class);
@endphp

<h1>Dashboard</h1>
<p class="sid-muted">Selamat datang, {{ auth()->user()->name }}.</p>

@can('penduduk.lihat')
    <div class="sid-form-grid" style="gap:16px">
        <div class="sid-card">
            <div class="sid-muted sid-text-sm">Penduduk aktif</div>
            <div style="font-size:var(--text-3xl);font-weight:600">
                {{ number_format($residents->countActive(), 0, ',', '.') }}
            </div>
            <div class="sid-muted sid-text-sm">
                dari {{ number_format($residents->countAll(), 0, ',', '.') }} total tercatat
            </div>
        </div>

        @can('keluarga.lihat')
            <div class="sid-card">
                <div class="sid-muted sid-text-sm">Kartu keluarga</div>
                <div style="font-size:var(--text-3xl);font-weight:600">
                    {{ number_format($families->countAll(), 0, ',', '.') }}
                </div>
                <div class="sid-muted sid-text-sm">dalam lingkup akses Anda</div>
            </div>
        @endcan

        <div class="sid-card">
            <div class="sid-muted sid-text-sm">Lingkup wilayah Anda</div>
            <div style="font-size:var(--text-lg);font-weight:600;margin-top:4px">
                @if ($scope->isRegionLimited())
                    {{ $scope->regionPaths === [] ? 'Belum ditugaskan' : 'Terbatas' }}
                @else
                    Seluruh desa
                @endif
            </div>
            @if ($scope->isRegionLimited() && $scope->regionPaths === [])
                <div class="sid-text-sm" style="color:var(--color-warning)">
                    Hubungi Admin Desa untuk penugasan wilayah.
                </div>
            @endif
        </div>
    </div>
@endcan

<div class="sid-card sid-mt-4">
    <h2>Pekerjaan berikutnya</h2>
    <p class="sid-text-sm sid-muted">
        Modul kependudukan sudah dapat dipakai. Impor data massal dari Excel,
        pencatatan mutasi (lahir, mati, pindah), dan statistik dijadwalkan pada
        Sprint 3; layanan persuratan pada Sprint 4–5.
    </p>
    <ul>
        @can('penduduk.lihat')
            <li><a href="{{ route('penduduk.index') }}">Data Penduduk</a></li>
        @endcan
        @can('keluarga.lihat')
            <li><a href="{{ route('keluarga.index') }}">Kartu Keluarga</a></li>
        @endcan
        @can('wilayah.lihat')
            <li><a href="{{ route('wilayah.index') }}">Wilayah</a></li>
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
