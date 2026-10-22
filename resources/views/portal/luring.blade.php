{{--
  Halaman luring — satu-satunya halaman yang disimpan service worker.

  TIDAK MEMUAT DATA APA PUN dari basis data, bahkan nama desa: ia tersimpan di
  perangkat sampai versi cache dinaikkan, sehingga apa pun yang ada di sini
  ikut tersimpan pada ponsel yang mungkin dipakai bergantian.

  Gayanya disalin sebaris, bukan lewat @vite: berkas CSS dapat belum pernah
  terunduh di perangkat yang memasang aplikasi lalu langsung kehilangan sinyal,
  dan halaman yang menjelaskan ketiadaan koneksi tidak boleh ikut gagal karena
  satu permintaan jaringan.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Tidak ada koneksi</title>
    <style nonce="{{ Illuminate\Support\Facades\Vite::cspNonce() }}">
        /*
         * Warna disusun sebagai variabel, dan mode gelap hanya menimpa
         * variabelnya — BUKAN aturannya.
         *
         * Versi pertama berkas ini menaruh blok @media di tengah, lalu
         * mendefinisikan .kartu sesudahnya. Keduanya berspesifisitas sama,
         * jadi aturan yang belakangan menang: kartunya tetap putih di mode
         * gelap sementara teksnya sudah terang, dan halaman ini praktis tidak
         * terbaca. Ketahuan hanya karena dibuka langsung di peramban — tidak
         * ada uji yang menangkap urutan aturan CSS.
         */
        :root {
            color-scheme: light dark;
            --latar: #F7F8F7;
            --teks: #16221C;
            --kartu: #FFFFFF;
            --garis: #DDE3DF;
            --aksen: #1B5E3F;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --latar: #0C140F;
                --teks: #E8EDEA;
                --kartu: #121C16;
                --garis: #24332B;
                --aksen: #2E7D52;
            }
        }

        body {
            margin: 0; min-height: 100dvh;
            display: flex; align-items: center; justify-content: center;
            padding: 1.5rem;
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            font-size: 1rem; line-height: 1.6;
            background: var(--latar); color: var(--teks);
        }
        .kartu {
            max-width: 28rem; width: 100%;
            background: var(--kartu); border: 1px solid var(--garis);
            border-radius: 0.75rem; padding: 1.5rem;
        }
        h1 { margin: 0 0 .5rem; font-size: 1.25rem; }
        p { margin: 0 0 1rem; }
        ul { margin: 0 0 1.25rem; padding-left: 1.25rem; }
        li { margin-bottom: .375rem; }
        .tombol {
            display: inline-flex; align-items: center; justify-content: center;
            min-height: 2.75rem; padding: 0 1.25rem;
            border: 0; border-radius: .5rem; cursor: pointer;
            background: var(--aksen); color: #FFFFFF;
            font: inherit; font-weight: 500; text-decoration: none;
        }
    </style>
</head>
<body>
    <main class="kartu">
        <h1>Tidak ada koneksi internet</h1>

        <p>
            Halaman ini tidak dapat dimuat karena perangkat Anda sedang tidak
            terhubung ke internet.
        </p>

        <p>
            Data layanan desa sengaja <strong>tidak disimpan</strong> di
            perangkat. Alasannya: ponsel sering dipakai bergantian, dan data
            warga yang tertinggal di dalamnya tidak dapat ditarik kembali.
        </p>

        <ul>
            <li>Periksa sambungan data atau Wi-Fi Anda, lalu coba lagi.</li>
            <li>Pengurusan surat tetap dapat dilakukan di kantor desa.</li>
        </ul>

        {{-- Tanpa JavaScript: tautan ke beranda, bukan tombol "muat ulang". --}}
        <a class="tombol" href="/">Coba lagi</a>
    </main>
</body>
</html>
