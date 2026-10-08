<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Panel administrasi tidak boleh terindeks mesin pencari. --}}
    <meta name="robots" content="noindex, nofollow">

    <title inertia>{{ config('app.name') }}</title>

    {{--
      Tema diterapkan SEBELUM CSS dimuat, langsung di <head>.
      Tanpa ini, pengguna mode gelap melihat kedipan putih di setiap muat
      halaman — mengganggu, dan terasa seperti aplikasi yang belum selesai.
    --}}
    <script>
        (function () {
            try {
                var stored = localStorage.getItem('sid-theme');
                var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                if (stored === 'dark' || (stored === null && prefersDark)) {
                    document.documentElement.classList.add('dark');
                }
            } catch (e) {
                // Penyimpanan diblokir (mode penyamaran) — pakai mode terang.
            }
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.ts'])
    @inertiaHead
</head>
<body class="h-full antialiased">
    @inertia
</body>
</html>
