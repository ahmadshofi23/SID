<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') — Desa {{ $village->name }}</title>
    <link rel="stylesheet" href="{{ asset('css/sid.css') }}">
</head>
<body>
<div class="sid-shell">
    <header class="sid-topbar">
        <a href="{{ route('dashboard') }}" class="sid-topbar__brand" style="text-decoration:none">
            🏛 Desa {{ $village->name }}
        </a>
        <div class="sid-topbar__meta">
            {{ auth()->user()->name }}
            @foreach (auth()->user()->roles as $role)
                <span class="sid-badge">{{ $role->name }}</span>
            @endforeach
            <form method="POST" action="{{ route('logout') }}" style="display:inline">
                @csrf
                <button type="submit" class="sid-btn sid-btn--text" style="color:#fff">Keluar</button>
            </form>
        </div>
    </header>

    <div class="sid-layout">
        {{-- Menu yang tidak diizinkan TIDAK ditampilkan (docs/07 §2.4).
             Ini kenyamanan, bukan kontrol keamanan — penegakannya ada di
             middleware `izin:` pada setiap rute. --}}
        <nav class="sid-sidebar" aria-label="Menu utama">
            <div class="sid-sidebar__group">
                <div class="sid-sidebar__title">Beranda</div>
                <a href="{{ route('dashboard') }}" class="sid-sidebar__link"
                   @if (request()->routeIs('dashboard')) aria-current="page" @endif>Dashboard</a>
            </div>

            @canany(['penduduk.lihat', 'keluarga.lihat', 'wilayah.lihat'])
                <div class="sid-sidebar__group">
                    <div class="sid-sidebar__title">Kependudukan</div>
                    @can('penduduk.lihat')
                        <a href="{{ route('penduduk.index') }}" class="sid-sidebar__link"
                           @if (request()->routeIs('penduduk.*')) aria-current="page" @endif>Data Penduduk</a>
                    @endcan
                    @can('keluarga.lihat')
                        <a href="{{ route('keluarga.index') }}" class="sid-sidebar__link"
                           @if (request()->routeIs('keluarga.*')) aria-current="page" @endif>Kartu Keluarga</a>
                    @endcan
                    @can('wilayah.lihat')
                        <a href="{{ route('wilayah.index') }}" class="sid-sidebar__link"
                           @if (request()->routeIs('wilayah.*')) aria-current="page" @endif>Wilayah</a>
                    @endcan
                </div>
            @endcanany

            @can('audit.lihat')
                <div class="sid-sidebar__group">
                    <div class="sid-sidebar__title">Pengaturan</div>
                    <a href="{{ route('audit.index') }}" class="sid-sidebar__link"
                       @if (request()->routeIs('audit.*')) aria-current="page" @endif>Log Aktivitas</a>
                </div>
            @endcan
        </nav>

        <main class="sid-main" style="flex:1">
            <div class="sid-container">
                @foreach (['status' => 'success', 'warning' => 'warning', 'error' => 'danger'] as $key => $type)
                    @if (session($key))
                        <x-alert :type="$type">{{ session($key) }}</x-alert>
                    @endif
                @endforeach

                @if ($errors->any() && ! $errors->hasAny(['identifier', 'code']))
                    <x-alert type="danger" title="Data belum dapat disimpan">
                        <ul style="margin:0;padding-left:1.2em">
                            @foreach ($errors->all() as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    </x-alert>
                @endif

                @yield('content')
            </div>
        </main>
    </div>

    <footer class="sid-footer">
        Sistem Informasi Desa {{ $village->name }} &middot;
        {{ now()->setTimezone(config('sid.timezone'))->translatedFormat('d F Y') }}
    </footer>
</div>
@stack('scripts')
</body>
</html>
