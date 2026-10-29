{{--
  Penanda aplikasi web progresif — docs/03 §2.3, docs/07 §8.

  Disertakan dari DUA kerangka: akar Inertia (panel & portal warga) dan
  kerangka Blade portal publik. Satu berkas, supaya keduanya tidak menyimpang
  — ikon yang berbeda antara halaman depan dan halaman setelah masuk akan
  membuat aplikasi terpasang menampilkan dua rupa.

  Pendaftaran service worker ditulis SEBARIS ber-nonce, bukan sebagai berkas
  JavaScript terpisah. Portal publik sengaja tidak memuat bundel JS sama
  sekali (KEP-016), dan beberapa ratus bita di sini tidak mengubah itu:
  halamannya tetap utuh tanpa JavaScript, dan yang hilang bila skrip ini
  tidak berjalan hanyalah kemampuan memasang ke layar utama.
--}}
<link rel="manifest" href="{{ route('pwa.manifest') }}">
<meta name="theme-color" content="#1B5E3F">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ \Illuminate\Support\Str::limit($village->name ?: 'Desa', 12, '') }}">
<link rel="apple-touch-icon" href="/ikon/apple-touch-icon.png">
<link rel="icon" href="/ikon/icon-192.png" sizes="192x192" type="image/png">

<script nonce="{{ Illuminate\Support\Facades\Vite::cspNonce() }}">
    /*
     * Didaftarkan setelah `load` agar tidak berebut bandwidth dengan muatan
     * halaman pertama — pada jaringan 3G desa, service worker yang diunduh
     * bersamaan dengan CSS memperlambat justru hal yang hendak dipercepat.
     */
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/sw.js').catch(function () {
                // Gagal mendaftar bukan kesalahan yang perlu ditampilkan:
                // aplikasi tetap berjalan penuh, hanya tidak dapat dipasang.
            });
        });
    }
</script>
