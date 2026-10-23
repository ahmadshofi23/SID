# Uji Beban, Volume & Tekanan

Perkakas untuk kriteria non-fungsional di
[`docs/09-testing-qa.md` §4.5](../../docs/09-testing-qa.md) dan NFR-PERF di
[`docs/03-srs.md` §5.1](../../docs/03-srs.md). Hasil pengukuran yang sudah
dijalankan ada di [`docs/22-uji-beban-sprint-7.md`](../../docs/22-uji-beban-sprint-7.md).

| Berkas | Gunanya |
|---|---|
| `beban-campuran.js` | Uji beban k6: 50 pengguna bersamaan, campuran baca/tulis |
| `tekanan.js` | Uji tekanan k6: 10 → 400 VU bertangga, lalu pemulihan |
| `ukur-kueri.php` | Uji volume: waktu kueri di atas 20.000 penduduk & 1,5 juta audit log |
| `siapkan-data-k6.php` | Menulis `data-uji.json` (id pengaduan & kata pencarian nyata) |
| `pustaka.js` | Pembantu bersama k6 (CSRF, login, metrik baca/tulis) |
| `router.php` | Router server bawaan PHP — lihat §3 |

Yang **tidak** ada di sini: uji konkurensi penomoran surat. Ia sudah ada
sebagai uji otomatis di
[`tests/Feature/Letter/LetterNumberingConcurrencyTest.php`](../Feature/Letter/LetterNumberingConcurrencyTest.php)
(TC-SRT-08) dan menjalankan 20 penerbitan benar-benar bersamaan dengan
`pcntl_fork`, satu proses dan satu koneksi basis data per petugas. Menulisnya
ulang di k6 hanya akan menguji hal yang sama dengan lebih lemah: k6 tidak
dapat memeriksa deret nomor di dalam basis data, hanya kode HTTP-nya.

---

## 1. Prasyarat

```bash
brew install k6          # atau: https://k6.io/docs/get-started/installation/
k6 version
```

PHP 8.5 (Homebrew), MySQL 8.4, dan Redis seperti pada pengembangan biasa.
Seluruh perintah di bawah dijalankan dari akar proyek.

---

## 2. Siapkan basis data volume

```bash
php artisan sid:seed-volume --segar
```

Perintah ini **tidak menyentuh `sid_desa`**. Ia membuat basis data terpisah
`sid_desa_volume`, memigrasinya, menyemai data dasar + akun demo, lalu
menambahkan 20.000 penduduk, ± 6.700 KK, 1,5 juta baris audit log, 2.000
pengajuan surat, dan 2.000 pengaduan. Di MacBook Air M-series: ± 70 detik.

Ia **menolak berjalan bila `APP_ENV=production`**, tanpa opsi pemaksa —
alasannya ada di
[`app/Console/Commands/SeedVolume.php`](../../app/Console/Commands/SeedVolume.php).

Pilihan lain:

```bash
php artisan sid:seed-volume --penduduk=50000 --audit=3000000   # uji di atas NFR
php artisan sid:seed-volume --basis-data=sid_desa_vol2 --segar  # basis data lain
php artisan sid:seed-volume --bersihkan                        # HAPUS sid_desa_volume
```

Rantai hash audit log tetap utuh setelah penyemaian, dan itu dapat diperiksa:

```bash
DB_DATABASE=sid_desa_volume php artisan sid:verify-audit-chain
```

---

## 3. Jalankan server uji

```bash
PHP_CLI_SERVER_WORKERS=8 \
DB_DATABASE=sid_desa_volume \
APP_DEBUG=false \
CACHE_STORE=array \
php -d opcache.enable_cli=1 -d opcache.validate_timestamps=0 \
    -S 127.0.0.1:8111 -t public tests/Load/router.php
```

Aset frontend harus sudah dibangun lebih dulu (`npm run build`), karena
direktif `@vite` melempar galat bila `public/build/manifest.json` tidak ada —
tanpa itu setiap halaman Inertia menjawab 500.

Setiap variabel di atas mengubah apa yang terukur, jadi tidak ada yang boleh
dihapus tanpa sadar:

- **`PHP_CLI_SERVER_WORKERS=8`** — tanpa ini server bawaan PHP melayani
  permintaan satu demi satu, dan uji "50 pengguna bersamaan" hanya mengukur
  panjang antrean. Delapan ≈ jumlah inti CPU mesin uji.
- **`CACHE_STORE=array`** — **mematikan pembatas laju per IP**, dan ini
  disengaja. `throttle:20,1` pada login dan `throttle:5,60` pada pengiriman
  pengaduan dihitung **per alamat IP**. k6 menekan dari satu host, sehingga 50
  pengguna tiruan tampak sebagai satu alamat IP dan ditolak 429 — bukan karena
  aplikasinya lambat, melainkan karena pembatasnya bekerja dengan benar.
  Mematikannya adalah cara yang benar untuk menirukan 50 pengguna dengan 50
  alamat IP. Konsekuensi yang harus diingat: angka yang terukur adalah angka
  aplikasi **tanpa** pembatas laju, dan pembatas lajunya sendiri diuji di
  tempat lain (uji fitur + uji penetrasi Sprint 7).
  Catat juga arah biasnya: cache array membuat `settings` dan statistik portal
  dihitung ulang pada **setiap** permintaan, sehingga angkanya **lebih buruk**
  daripada produksi yang memakai Redis — pesimistis, bukan optimistis.
- **`APP_DEBUG=false`** — dengan `true`, setiap kueri disimpan di memori untuk
  halaman galat dan Laravel mengumpulkan jejak tumpukan; pada 1,5 juta baris
  audit log itu terukur.
- **`opcache.enable_cli=1`** — tanpanya setiap permintaan mengompilasi ulang
  seluruh berkas PHP aplikasi. Yang terukur kemudian adalah kecepatan
  kompilasi PHP, bukan kecepatan aplikasi. Produksi selalu memakai opcache.

Server bawaan PHP **bukan** php-fpm + nginx, dan angkanya tidak boleh dibaca
sebagai angka produksi. Lihat `docs/22-uji-beban-sprint-7.md` §3.

---

## 4. Jalankan uji

```bash
DB_DATABASE=sid_desa_volume php tests/Load/siapkan-data-k6.php   # sekali, setelah seed

k6 run tests/Load/beban-campuran.js                 # 30 menit (kriteria §4.5)
DURASI=5m k6 run tests/Load/beban-campuran.js       # versi singkat
k6 run tests/Load/tekanan.js                        # ± 8 menit

DB_DATABASE=sid_desa_volume php tests/Load/ukur-kueri.php --ulang=20
```

`BASE_URL` dapat diarahkan ke server lain:
`BASE_URL=https://staging.example.test k6 run tests/Load/beban-campuran.js`.

Hasil rinci ditulis ke `tests/Load/hasil-beban.json` dan
`tests/Load/hasil-tekanan.json` (keduanya tidak di-commit).

---

## 5. Arti angkanya

### Uji beban — `beban-campuran.js`

Ambangnya ditegakkan k6 sendiri lewat `thresholds`, bukan dibaca manusia dari
layar; uji gagal bila salah satunya terlampaui.

| Metrik | Arti | Batas (docs/09 §4.5) |
|---|---|---|
| `sid_baca_ms` p95 | 95% permintaan **baca** selesai di bawah angka ini | ≤ 500 ms (NFR-PERF-01) |
| `sid_tulis_ms` p95 | 95% permintaan **tulis** selesai di bawah angka ini | ≤ 1.000 ms (NFR-PERF-02) |
| `sid_galat` rate | Bagian permintaan yang jawabannya tidak dapat diterima | < 0,1% |

Baca dan tulis **sengaja dipisah** menjadi dua metrik: kriterianya memang dua
angka, dan satu p95 gabungan tidak dapat diuji terhadap keduanya — ia akan
ditenggelamkan oleh pembacaan yang jumlahnya jauh lebih banyak.

Yang **tidak** dihitung galat:

- **302** pada penulisan — seluruh penulisan aplikasi ini menjawab dengan
  redirect (pola Inertia + `back()`). Menuntut 200 akan melaporkan 100% galat
  pada aplikasi yang bekerja sempurna.
- **429** pada pengiriman pengaduan publik — itu jawaban yang **benar** dari
  pembatas laju FR-PGD-08, bukan kegagalan melayani.

Komposisi 50 VU-nya meniru satu hari kerja, bukan pembagian rata: 30 warga
anonim di portal, 15 petugas membaca panel, 5 petugas menulis. Alasannya ada
di kepala `beban-campuran.js`.

### Uji tekanan — `tekanan.js`

Kriterianya **bentuk kurva**, bukan satu angka, sehingga skrip ini sengaja
tidak punya `thresholds`. Tiga hal yang dibaca dari hasilnya:

1. Waktu respons **naik mulus** seiring beban, atau melompat ke puluhan detik
   sekaligus (tanda antrean tak berbatas).
2. Galat yang muncul berupa **penolakan cepat** (503/429 — server masih
   menjawab) atau **putus sambungan** (macet total).
3. Setelah beban turun ke 10 VU, waktu respons **kembali sendiri** ke angka
   semula — itulah "pulih otomatis".

### Uji volume — `ukur-kueri.php`

Setiap baris melaporkan median dan p95 dari `--ulang` kali percobaan, dengan
satu kali pemanasan dibuang. Baris yang paling penting adalah
`Nama mengandung LIKE '%…%'`: itulah kriteria eksplisit §4.5 (≤ 200 ms), dan
konsekuensi langsung dari tiadanya `pg_trgm` di MySQL (KEP-002).

---

## 6. Setelah selesai

```bash
php artisan sid:seed-volume --bersihkan     # hapus sid_desa_volume
```

`sid_desa` tidak pernah tersentuh, jadi tidak ada yang perlu dipulihkan di
sana. Tabel `complaint_actions` di basis data volume akan memuat ribuan
tindak lanjut hasil uji — itu normal, dan itu salah satu alasan uji ini tidak
boleh dijalankan di basis data kerja.
