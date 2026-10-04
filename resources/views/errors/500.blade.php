<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Terjadi gangguan</title>
    <link rel="stylesheet" href="{{ asset('css/sid.css') }}">
</head>
<body>
<main class="sid-auth">
    <div class="sid-card sid-card--auth">
        <h1>Terjadi gangguan</h1>
        <p>
            Sistem mengalami gangguan saat memproses permintaan Anda. Data yang
            sudah tersimpan tidak terpengaruh.
        </p>
        <p>Silakan coba beberapa saat lagi. Bila terus terjadi, laporkan ke
            Admin Desa dengan menyebutkan kode berikut:</p>

        {{-- Correlation ID, bukan stack trace: pengguna mendapat sesuatu yang
             dapat dilaporkan, penyerang tidak mendapat informasi apa pun
             (docs/08 §6 A05). --}}
        <p class="sid-secret">{{ $correlationId ?? 'tidak tersedia' }}</p>

        <a href="{{ url('/') }}" class="sid-btn sid-btn--secondary">Halaman utama</a>
    </div>
</main>
</body>
</html>
