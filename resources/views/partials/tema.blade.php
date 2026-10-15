{{--
  Tema diterapkan SEBELUM CSS dimuat, langsung di <head>.
  Tanpa ini, pengguna mode gelap melihat kedipan putih di setiap muat
  halaman — mengganggu, dan terasa seperti aplikasi yang belum selesai.

  Skrip ini sebaris, jadi ia hanya berjalan bila CSP mengizinkannya: atribut
  nonce di bawah harus cocok dengan 'nonce-...' pada direktif script-src yang
  dipasang App\Http\Middleware\SecurityHeaders. Keduanya satu paket — mengubah
  salah satunya saja membuat mode gelap kembali berkedip putih tanpa pesan
  galat selain di konsol peramban.
--}}
<script nonce="{{ Illuminate\Support\Facades\Vite::cspNonce() }}">
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
