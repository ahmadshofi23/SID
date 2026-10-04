<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Dashboard') — Desa {{ $village->name }}</title>
    <link rel="stylesheet" href="{{ asset('css/sid.css') }}">
</head>
<body>
<div class="sid-shell">
    <header class="sid-topbar">
        <div>
            <span class="sid-topbar__brand">🏛 Desa {{ $village->name }}</span>
        </div>
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

    <main class="sid-main">
        <div class="sid-container">
            @foreach (['status' => 'success', 'warning' => 'warning', 'error' => 'danger'] as $key => $type)
                @if (session($key))
                    <x-alert :type="$type">{{ session($key) }}</x-alert>
                @endif
            @endforeach

            @yield('content')
        </div>
    </main>

    <footer class="sid-footer">
        Sistem Informasi Desa {{ $village->name }} &middot;
        {{ now()->setTimezone(config('sid.timezone'))->translatedFormat('d F Y') }}
    </footer>
</div>
</body>
</html>
