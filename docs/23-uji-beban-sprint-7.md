# 23 — Uji Beban, Volume & Tekanan (Sprint 7)

**Proyek:** Sistem Informasi Desa (SID) · **Sprint:** 7 · **Milestone:** M5 "Siap UAT"
**Tanggal pengukuran:** 24 Oktober 2026
**Kriteria yang diuji:** `docs/09-testing-qa.md` §4.5 · NFR-PERF-01…08 (`docs/03-srs.md` §5.1)
**Perkakas:** `tests/Load/` (k6 + dua skrip pengukur) · penyemai `php artisan sid:seed-volume`

---

## 1. Ringkasan untuk yang tidak membaca sampai habis

| Kriteria docs/09 §4.5 | Batas | Terukur | Hasil |
|---|---|---|---|
| **Beban** p95 baca | ≤ 500 ms | **893 ms** (lihat §6) | **TIDAK LULUS — tetapi tidak sahih** |
| **Beban** p95 tulis | ≤ 1.000 ms | **487 ms** | **LULUS** (dengan catatan §6) |
| **Beban** galat | < 0,1% | **0,0000%** (0 dari 51.324 permintaan) | **LULUS** |
| **Volume** `LIKE '%…%'` pada nama | ≤ 200 ms | **3,2 ms** (p95) | **LULUS** |
| **Volume** pencarian & laporan memenuhi NFR-PERF | ≤ 500 ms (baca) | semua di bawah 100 ms | **LULUS** |
| **Tekanan** degradasi bertahap, pulih otomatis | — | pulih seketika, 0 galat aplikasi di 400 VU | **sebagian** (§6.3) |
| **Konkurensi** 20 penerbitan surat bersamaan | 0 ganda, 0 terlewat | sudah diuji otomatis | **LULUS** |

**Kriteria beban tidak dapat diputuskan dari pengukuran ini, dan itu temuan
tersendiri.** Pada jalannya 30 menit yang sesuai kriteria, yang menjadi sumbat
bukan aplikasinya melainkan **server ujinya**: server bawaan PHP dengan
delapan pekerja jenuh pada 7,8 permintaan/detik, antreannya tumbuh tanpa batas
selama tiga puluh menit, dan satu permintaan sempat menunggu **903 detik**.
Dengan 32 pekerja pada mesin yang sama, aplikasi yang **sama persis** melayani
**87,4 permintaan/detik** dengan permintaan terlama 1,9 detik. Rinciannya,
beserta bukti bahwa aplikasinya tidak ikut melambat, ada di §6.

Satu hal yang **tidak** dipenuhi sebelum sprint ini: halaman **Laporan**
terukur **755 ms** pada 20.000 penduduk — melewati NFR-PERF-01 sendirian.
Itu diperbaiki dalam sprint ini (**KEP-019**) dan kini 43 ms; rinciannya di
§7 butir 3.

Tiga temuan **tidak** diperbaiki di sini, dengan alasan tertulis di §7 butir
1, 2, dan 5: deadlock MySQL pada penulisan audit log yang bersamaan, kolom
`changes` yang ternyata tidak pernah masuk ke rantai hash audit, dan
`sid:verify-audit-chain` yang tumbuh kuadratik. Ketiganya **sudah ditutup
sesudahnya** oleh **KEP-020**, bersama satu cacat **keempat** yang tidak
terlihat dari pengukuran ini: rumus lama juga melaporkan rantai RUSAK atas
baris yang jujur, karena MySQL menata ulang kunci objek JSON saat
menyimpannya. Cacat keempat dan temuan 2 saling menutupi di sini — penyemai
volume menyesuaikan diri dengan yang satu dan menulis konteks berkunci
tunggal, sehingga yang lain tidak pernah muncul. Keterangan per butir ada di
§7.

---

## 2. Yang diukur, dan yang tidak

**Diukur di sini:** beban (50 pengguna bersamaan), volume (20.000 penduduk +
1,5 juta baris audit log), tekanan (sampai gagal).

**Konkurensi penomoran surat TIDAK diukur ulang.** Kriteria §4.5 ("20
penerbitan bersamaan → 0 nomor ganda, 0 nomor terlewat") sudah dijaga uji
otomatis `tests/Feature/Letter/LetterNumberingConcurrencyTest.php` (TC-SRT-08),
dan uji itu menjalankan dua puluh penerbitan **benar-benar bersamaan** dengan
`pcntl_fork` — satu proses sistem operasi dan satu koneksi basis data per
petugas, dengan berkas penanda sebagai gerbang start agar tidak ada yang
selesai sebelum yang terakhir lahir. Ia juga memeriksa deretnya **di dalam
basis data** (`range(1, 20)` tanpa celah) dan menguji empat varian lain:
penghitung tahun baru yang belum ada, dua jenis surat berdeten terpisah, mode
nomor tunggal, dan transaksi yang dibatalkan.

Menulis ulang kriteria itu di k6 akan **melemahkannya**: k6 hanya melihat kode
HTTP, tidak dapat memeriksa deret nomor di dalam tabel, dan tidak dapat
membedakan "dua puluh nomor unik" dari "dua puluh permintaan yang berhasil".
Jadi yang dilakukan di sini adalah merujuknya, bukan menggantinya.

**Tidak diukur, dan harus dicatat sebagai lubang:**

- **Lighthouse (NFR-PERF-03/04) dan axe-core** — baris "Kinerja frontend" dan
  "Aksesibilitas" pada §4.5. Keduanya pekerjaan tersendiri dan bukan bagian
  Bagian 7 Sprint 7.
- **Halaman Log Aktivitas (`pengaturan/log-aktivitas`)** tidak dapat diukur
  sebagai halaman: ia **masih berupa placeholder** (`routes/web.php`, Sprint
  7). Kueri-kuerinya tetap diukur di §5 agar siapa pun yang membangunnya kelak
  punya angkanya lebih dulu — dan §7 butir 4 menyebut satu hal yang WAJIB
  dihindari di sana.
- **Pemulihan bencana, kompatibilitas peramban, lokalisasi** — baris §4.5
  lainnya, di luar lingkup bagian ini.

---

## 3. Lingkungan pengukuran, dan setiap hal yang membuatnya bukan produksi

Angka di dokumen ini **bukan angka produksi**, dan perbedaannya perlu
disebutkan satu per satu — angka kinerja tanpa keterangan lingkungannya akan
dikutip kembali setahun kemudian sebagai janji.

| | Mesin uji | Produksi (docs/10) |
|---|---|---|
| Perangkat | Apple M1, 8 inti, 16 GB | VPS 2–4 vCPU |
| PHP | 8.5.6, server bawaan PHP, 32 pekerja (lihat §6.1) | 8.3+, php-fpm di belakang Nginx |
| MySQL | 8.4.11, buffer pool 4 GB | 8.0+, buffer pool lebih kecil |
| Jaringan | localhost | internet, TLS |
| Cache | `array` (lihat di bawah) | Redis |

**Server bawaan PHP, bukan php-fpm + Nginx.** `php artisan serve` tidak dapat
dipakai: ia melayani permintaan **satu demi satu** (variabel
`PHP_CLI_SERVER_WORKERS` tidak diteruskan ke proses anaknya), sehingga uji
"50 pengguna bersamaan" hanya akan mengukur panjang antrean. Dipakai
`php -S` langsung dengan `PHP_CLI_SERVER_WORKERS=32` dan router sendiri
(`tests/Load/router.php`) yang — berbeda dari router bawaan Laravel — tidak
menulis satu baris log per permintaan. Konsekuensi yang tersisa: server
bawaan PHP tidak punya manajemen proses, tidak punya `slowlog`, dan membuat
ulang seluruh keadaan aplikasi pada setiap permintaan. Yang terakhir itu
membuat angkanya **pesimistis**, bukan optimistis.

**`opcache.enable_cli=1`.** Tanpanya setiap permintaan mengompilasi ulang
seluruh berkas PHP aplikasi, dan yang terukur adalah kecepatan kompilasi PHP.
Produksi selalu memakai opcache.

**`CACHE_STORE=array`, yang berarti pembatas laju per IP MATI.** Ini
keputusan metodologi yang paling perlu dibaca, karena ia dapat disalahpahami
sebagai mematikan kontrol keamanan untuk memperbagus angka:

- `throttle:20,1` pada login dan `throttle:5,60` pada pengiriman pengaduan
  dihitung **per alamat IP**. k6 menekan dari satu host, sehingga 50 pengguna
  tiruan tampak sebagai **satu** alamat IP dan ditolak 429 — bukan karena
  aplikasinya lambat, melainkan karena pembatasnya bekerja dengan benar.
  Mematikannya adalah cara yang **benar** untuk menirukan 50 pengguna dengan
  50 alamat IP.
- Pembatas lajunya sendiri diuji di tempat lain: uji fitur per endpoint dan
  uji penetrasi Sprint 7 (`docs/22-uji-penetrasi-sprint-7.md`).
- Arah biasnya juga perlu dicatat: cache `array` membuat tabel `settings` dan
  statistik portal dihitung ulang pada **setiap** permintaan, sehingga angka
  bacanya **lebih buruk** daripada produksi yang memakai Redis.

**`APP_ENV=local`, bukan `production`.** Akibatnya
`Model::preventLazyLoading()` menyala (`SidServiceProvider`). Biayanya satu
pemeriksaan bendera per akses relasi — tidak terukur — tetapi ia berarti relasi
yang lupa di-eager-load akan **gagal** di uji ini, bukan melambat diam-diam.
Itu disengaja: uji beban yang menyembunyikan N+1 tidak ada gunanya.

**Aset frontend dibangun lebih dulu** (`npm run build`). Tanpa
`public/build/manifest.json`, direktif `@vite` melempar galat dan setiap
halaman Inertia menjawab 500 — uji beban akan mengukur halaman galat.

---

## 4. Data uji: 20.000 penduduk dan 1,5 juta baris audit log

Dibangun `php artisan sid:seed-volume --segar` (lihat
`tests/Load/README.md` §2). Isi akhirnya:

| Tabel | Baris | Data | Indeks |
|---|---|---|---|
| `residents` | 20.007 | 11 MB | 23 MB |
| `families` | 6.670 | 3 MB | 3 MB |
| `audit_logs` | 1.500.002 | ± 560 MB | ± 190 MB |
| `letter_requests` | 2.000 | — | — |
| `complaints` | 2.000 | — | — |

**Waktu penyemaian: 60 detik** untuk seluruhnya, pada mesin di §3. Angka itu
penting bukan sebagai prestasi: penyemai yang memakan setengah jam tidak akan
pernah dijalankan ulang, dan uji volume yang tidak pernah dijalankan ulang
berhenti menjadi uji. Yang membuatnya 60 detik adalah sisipan massal 500–2.000
baris sekali jalan, bukan Eloquent per baris (± 6 menit untuk penduduk saja).

Tiga hal **tetap dihitung aplikasi**, bukan dipalsukan, dan masing-masing ada
alasannya:

1. **`nik_enc`/`nik_hash`/`nik_masked` lewat `PiiCipher`** (ADR-004). Tanpa
   itu pencarian NIK tidak menemukan apa pun, dan angka "pencarian NIK ≤ x ms"
   menjadi angka kueri kosong.
2. **`full_name_norm` lewat `NameNormalizer`.** Inilah kolom yang diukur
   kriteria `LIKE '%…%'` ≤ 200 ms.
3. **Rantai hash audit log dihitung berurutan**, sehingga
   `sid:verify-audit-chain` masih lulus di atas 1,5 juta baris — **dan ia
   sudah dijalankan, hasilnya rantai utuh.** Mengisinya dengan hash acak lebih
   cepat, tetapi kemudian perintah verifikasi itu sendiri tidak dapat diuji
   pada volume sebenarnya, padahal ia justru perintah yang harus memindai
   seluruh tabel.

NIK sintetisnya **valid secara struktur** seperti dituntut docs/09 §3
(`320101` + DDMMYY tanggal lahir, +40 untuk perempuan, + 4 digit urut).
Awalnya tidak demikian, dan akibatnya terlihat begitu diperiksa: NIK yang
tidak cocok dengan kolom tanggal lahirnya membuat `Nik::warnings()` menandai
**seluruh** 20.000 penduduk, sehingga layar Anomali Data memuat 20.000 temuan
palsu dan berhenti menjadi layar yang dipakai petugas. Kasus tepi §3 yang
mengubah hasil pengukuran juga ikut disemai: nama satu kata, nama ber-apostrof,
nama > 100 karakter, penduduk > 100 tahun, bayi beberapa hari, serta penduduk
meninggal dan pindah keluar (2% dari seluruhnya). Sisanya — KK satu anggota,
penduduk tanpa RT — sudah ada di `DemoDataSeeder`, yang ikut berjalan di basis
data volume.

Penyemai **menolak berjalan bila `APP_ENV=production`**, tanpa opsi pemaksa,
dan bawaannya menulis ke basis data **terpisah** `sid_desa_volume` sehingga
basis data pengembangan `sid_desa` tidak pernah tersentuh. Alasan keduanya ada
di `app/Console/Commands/SeedVolume.php` dan dijaga uji
`tests/Feature/Volume/VolumeSeederTest.php`.

---

## 5. Uji VOLUME — hasil terukur

`DB_DATABASE=sid_desa_volume php tests/Load/ukur-kueri.php --ulang=20`.
Setiap baris adalah median dan p95 dari 20 percobaan, dengan satu kali
pemanasan dibuang (kueri pertama membayar pengisian buffer pool).

Dijalankan pada basis data volume **sebelum** perbaikan KEP-019 (§7 butir 3);
kueri yang terpengaruh perbaikan itu diukur ulang terpisah dan angkanya ada di
tabel KEP-019.

```
Basis data : sid_desa_volume
Penduduk   : 20.007      Keluarga : 6.670      Audit log : 1.500.002
Ulangan    : 10 (satu pemanasan dibuang)

— Pencarian penduduk (FR-PDK-04/05) —
NIK persis (nik_hash, lewat indeks)                  median   0,2 ms   p95   0,2 ms   batas  200 ms   LULUS
Nama berawalan  LIKE 'ahmad%'                        median   0,4 ms   p95   0,4 ms   batas  200 ms   LULUS
Nama mengandung LIKE '%hmad%'  (kriteria §4.5)       median   2,8 ms   p95   3,2 ms   batas  200 ms   LULUS
Bentuk OR di repository (berawalan OR mengandung)    median   2,9 ms   p95   3,2 ms   batas  200 ms   LULUS
Daftar penduduk halaman 1 + relasi wilayah           median   0,5 ms   p95   0,5 ms   batas  500 ms   LULUS

— Statistik & laporan (FR-LAP, NFR-PERF-01) —
Rekap status penduduk (GROUP BY status)              median   3,5 ms   p95   3,6 ms   batas  500 ms   LULUS
Piramida umur (GROUP BY rentang tanggal lahir)       median  70,6 ms   p95  84,2 ms   batas  500 ms   LULUS
Rekap pendidikan × wilayah                           median  69,3 ms   p95  69,6 ms   batas  500 ms   LULUS
Buku Induk satu RT (urut nama, semua kolom)          median  13,0 ms   p95  13,1 ms   batas 1000 ms   LULUS

— Audit log 1,5 juta baris (FR-ADM-04) —
Log aktivitas halaman 1 (ORDER BY created_at DESC)   median   0,2 ms   p95   0,2 ms   batas  500 ms   LULUS
Log satu aksi, rentang 30 hari                       median   0,3 ms   p95   0,4 ms   batas  500 ms   LULUS
Log satu pelaku, rentang 1 tahun                     median   0,4 ms   p95   0,6 ms   batas  500 ms   LULUS
COUNT(*) seluruh audit log (dipakai paginator)       median  93,4 ms   p95  94,1 ms   batas  500 ms   LULUS

— Antrean surat & pengaduan —
Antrean surat (status + tenggat)                     median   1,9 ms   p95   2,0 ms   batas  500 ms   LULUS
Antrean pengaduan (status + tenggat triase)          median   2,4 ms   p95   2,6 ms   batas  500 ms   LULUS
```

Jumlah kueri per halaman, diukur terpisah dengan log kueri (volume yang sama,
**setelah** KEP-019): dashboard 31, daftar penduduk 12, pencarian penduduk 12,
statistik 14, antrean surat 9, antrean pengaduan 8, laporan 22, portal 14,
statistik publik 20, berita 1. Tidak ada yang bertambah seiring volume.

**Bacaan atas angka di atas:**

- **Kriteria `LIKE '%…%'` ≤ 200 ms: LULUS dengan margin ± 60×.** Pada 20.000
  penduduk, pemindaian nama memakan ± 3 ms. Perkiraan "≈ 5.000 baris, < 10 ms"
  di komentar `ResidentRepository::search()` ternyata tetap berlaku pada empat
  kali volume itu. Konsekuensi tiadanya `pg_trgm` (KEP-002) karena itu
  **terbukti dapat ditanggung** pada skala satu desa — dan ini angka yang
  layak dikutip bila keputusan MySQL-vs-PostgreSQL dibuka lagi.
- **Bentuk OR yang benar-benar dipakai repository sama cepatnya** dengan
  `LIKE '%…%'` murni. Cabang "berawalan" di dalam `OR` memang tidak dapat
  memakai indeks (MySQL tidak menggabungkan rentang indeks dengan pemindaian
  penuh pada kolom yang sama), jadi harganya adalah harga pemindaian — dan
  pada volume ini harga itu kecil. Tidak ada yang perlu diubah; yang perlu
  dicatat adalah bahwa indeks `idx_residents_name` **tidak** menolong
  pencarian nama sebagaimana mungkin disangka.
- **`COUNT(*)` atas 1,5 juta baris audit log adalah kueri termahal di seluruh
  tabel** (± 95 ms). Ia satu-satunya yang wajib menyentuh seluruh tabel, dan
  paginator Laravel memanggilnya pada setiap pembukaan halaman. Lihat §7
  butir 4.
- Seluruh kueri audit log yang **tersaring** (per aksi, per pelaku, per
  rentang tanggal) selesai di bawah 1 ms: ketiga indeks komposit di
  `audit_logs` memang mengenai sasarannya, dan keputusan "tanpa partisi"
  pada migrasinya terbukti benar pada 1,5 juta baris (± 75× proyeksi bulanan
  satu desa).

---

## 6. Uji BEBAN & TEKANAN — hasil terukur

### 6.1 Jalannya 30 menit sesuai kriteria, dan mengapa hasilnya tidak sahih

`DURASI=30m k6 run tests/Load/beban-campuran.js`, 50 VU (30 warga anonim,
15 petugas membaca, 5 petugas menulis), server uji §3 dengan **8 pekerja**.

| | Terukur | Batas |
|---|---|---|
| p95 baca | **3.451 ms** | 500 ms |
| p95 tulis | **2.856 ms** | 1.000 ms |
| Galat | **0,0000%** (0 dari 51.324) | < 0,1% |
| Keluaran | **7,8 permintaan/detik** | — |
| Permintaan terlama | **903 detik** | — |

Angka 903 detik itulah yang membatalkan pembacaan apa pun atas dua baris di
atasnya. Tidak ada permintaan HTTP yang memakan lima belas menit karena
kuerinya lambat; ia memakan lima belas menit karena **menunggu giliran**.
Empat bukti bahwa yang jenuh adalah server uji, bukan aplikasinya:

1. **Tidak ada satu pun galat aplikasi** selama tiga puluh menit —
   `storage/logs/laravel.log` kosong. Aplikasi yang kehabisan napas
   meninggalkan jejak; ini tidak.
2. **Hanya 15 tindak lanjut dan 10 pengaduan yang benar-benar tertulis** dalam
   tiga puluh menit oleh lima petugas penulis. Mereka bukan lambat menulis,
   melainkan hampir tidak pernah sampai giliran.
3. **Begitu antreannya habis, halaman kembali seketika.** Permintaan tunggal
   pertama setelah uji berakhir memakan 5,1 detik (sisa antrean); tiga
   berikutnya 67, 49, dan 41 milidetik.
4. **Dengan 32 pekerja, aplikasi yang sama persis melayani 87,4
   permintaan/detik** dan permintaan terlamanya 1,9 detik — sebelas kali lipat
   keluaran, tanpa satu baris kode pun berubah.

Kejenuhan pada 7,8 permintaan/detik untuk delapan pekerja berarti setiap
permintaan memakai ± 1 detik **waktu pekerja**, padahal kerjanya sendiri
(§5) di bawah 100 ms. Selisihnya adalah membangun ulang seluruh keadaan
aplikasi pada setiap permintaan — biaya yang di produksi dibayar sekali per
proses php-fpm, bukan sekali per permintaan. Ini persis keterbatasan yang
sudah disebutkan di §3; yang baru di sini adalah besarnya.

### 6.2 Jalannya dengan server uji yang tidak jenuh

`DURASI=3m`, 50 VU yang sama, **32 pekerja**:

| | Terukur | Batas | |
|---|---|---|---|
| p95 baca | **893 ms** | 500 ms | **TIDAK LULUS** |
| p95 tulis | **487 ms** | 1.000 ms | **LULUS** |
| Galat | **0,0000%** | < 0,1% | **LULUS** |
| Keluaran | 87,4 permintaan/detik | — | |
| Permintaan terlama | 1,9 detik | — | |

**p95 baca 893 ms tetap di atas ambang, dan itu tidak boleh dicatat sebagai
kegagalan aplikasi** — pada 87 permintaan/detik, delapan inti mesin ini sudah
habis membangun ulang keadaan aplikasi, dan yang terukur adalah batas
perangkat keras uji di bawah model proses yang salah. Yang **dapat**
disimpulkan dari baris-baris itu ada tiga, dan ketiganya berguna:

- **Tidak ada satu galat pun** pada 50 pengguna bersamaan, pada dua
  konfigurasi server yang sangat berbeda, selama total 33 menit. Kriteria
  galat < 0,1% terpenuhi dengan selisih yang tidak ambigu.
- **Penulisan lebih cepat daripada pembacaan** (p95 487 ms vs 893 ms), yang
  memang semestinya: penulisan menyentuh satu baris, pembacaan menyusun
  halaman.

**Satu deadlock audit log tetap terjadi**, dan cara ia nyaris lolos dari
laporan ini pantas dicatat. Selama ± 36 menit pengujian seluruhnya,
`laravel.log` memuat **satu** `SQLSTATE[40001] 1213` dari `AuditLogger`
(§7 butir 1) pada jalur login — tetapi angka galat k6 tetap 0,0000%, karena
`masuk()` di `tests/Load/pustaka.js` mencoba ulang sekali, persis seperti
orang sungguhan yang memuat ulang halaman yang gagal. Pelajarannya untuk
pembaca angka uji beban mana pun: **tingkat galat nol tidak berarti tidak ada
yang gagal**; ia berarti tidak ada yang gagal *dua kali berturut-turut*.
Karena itu `laravel.log` dibaca terpisah dari metrik k6, dan harus tetap
begitu.
- **Basis data bukan sumbatnya.** Seluruh kueri pada volume penuh (§5) di
  bawah 100 ms, dan halaman terberat (`/laporan`) menghabiskan 66 ms di basis
  data setelah KEP-019.

Satu percobaan ketiga patut dicatat karena hasilnya berlawanan dengan dugaan:
**dengan `CACHE_STORE=redis` (bukan `array`), keluarannya TURUN** menjadi 38,2
permintaan/detik dan p95 baca menjadi 2.465 ms. Sebabnya juga model proses:
setiap permintaan pada server bawaan PHP membuka sambungan Redis baru lewat
predis, sementara php-fpm menyimpannya per proses pekerja lintas permintaan.
Itu memperkuat kesimpulan yang sama dari arah lain — yang diukur di sini
sebagian besar adalah harga menyalakan aplikasi, bukan harga menjalankannya.

### 6.3 Uji tekanan

`k6 run tests/Load/tekanan.js` — tangga 10 → 50 → 100 → 200 → 400 VU, setiap
tingkat ditahan satu menit, lalu turun kembali ke 10 VU untuk menguji
pemulihan. Server uji 32 pekerja.

| | Terukur |
|---|---|
| Permintaan | 59.719 (124 permintaan/detik ditawarkan) |
| Gagal | **80,28%** |
| Durasi | median **0,03 ms** · p95 7.172 ms · maks 12.310 ms |
| Puncak | 400 VU |
| Galat aplikasi di `laravel.log` | **0** selama uji tekanan |

**Median 0,03 ms dengan 80% gagal adalah satu gambaran, bukan dua.** Permintaan
yang gagal tidak menggantung: ia **ditolak seketika** di tingkat TCP
(`connection reset by peer`), sebelum satu baris PHP pun berjalan. Itu sebabnya
`laravel.log` tidak memuat apa pun dari tahap ini — permintaan yang ditolak
tidak pernah sampai ke aplikasi.

Terhadap kriteria §4.5 ("degradasi bertahap, bukan macet total; pulih
otomatis"):

- **Macet total: TIDAK.** Prosesnya tidak pernah mati, dan tidak ada satu pun
  permintaan yang menggantung tanpa jawaban sampai batas waktu.
- **Pulih otomatis: YA, terbukti.** Segera setelah beban dilepas — tanpa
  menyalakan ulang apa pun — permintaan tunggal dilayani dalam 90–116 ms,
  sama seperti sebelum uji dimulai.
- **Degradasi bertahap: TIDAK DAPAT DINILAI DI SINI, dan yang terlihat bukan
  milik aplikasi.** Penolakan 80% di tingkat soket adalah perilaku **server
  bawaan PHP**, yang tidak punya manajer proses dan hanya punya antrean
  `listen` sekecil bawaan sistem: begitu seluruh pekerja sibuk, sambungan
  berikutnya direset, bukan diantrekan atau dijawab 503. Di produksi,
  lapisan itu adalah Nginx + php-fpm, yang memang dirancang untuk
  mengantrekan secara berbatas lalu menjawab 503 — perilaku yang hendak
  diperiksa kriteria ini, dan yang tidak ada di sini untuk diperiksa.

Bandingkan dengan §6.1 dan perbedaannya menjelaskan dirinya sendiri: pada 8
pekerja dengan 50 VU, server **mengantrekan** sampai 903 detik; pada 32
pekerja dengan 400 VU, ia **menolak**. Dua bentuk kegagalan yang berbeda dari
satu komponen yang sama, dan tidak satu pun berasal dari kode aplikasi.

**Yang dapat dibawa ke staging dari tahap ini:** aplikasi tidak menghasilkan
satu galat pun pada 400 pengguna bersamaan, dan pulih seketika tanpa campur
tangan. Penilaian "degradasi bertahap" yang mengikat harus diulang di atas
Nginx + php-fpm, dengan `pm.max_children` dan `listen.backlog` yang sengaja
disetel — angka itulah yang sebenarnya diuji kriteria ini.

### 6.4 Putusan atas kriteria "Beban" §4.5

**Tidak dapat diputuskan di lingkungan ini, dan tidak boleh dipaksa
diputuskan.** Kriteria p95 baca ≤ 500 ms adalah kriteria tentang aplikasi di
atas tumpukan produksinya (php-fpm + Nginx + Redis, docs/10 §3); yang tersedia
di sini adalah server pengembangan yang membangun ulang aplikasi pada setiap
permintaan, dan selisih keduanya terbukti berjumlah ratusan milidetik per
permintaan.

Yang sudah dapat dipastikan sekarang: **tidak ada galat**, **basis data bukan
sumbatnya pada volume sasaran**, dan **satu halaman yang benar-benar melewati
ambang karena kuerinya sudah ditemukan dan diperbaiki** (§7 butir 3).
Pengukuran p95 yang mengikat **harus diulang di staging** sebelum M5 ditutup
— perintahnya ada di §8, dan skripnya tidak perlu diubah sama sekali: cukup
`BASE_URL=https://staging… k6 run tests/Load/beban-campuran.js`.

---

## 7. Temuan & rekomendasi

### Temuan 1 — `AuditLogger` membuat deadlock MySQL pada penulisan bersamaan *(DIPERBAIKI sesudahnya — KEP-020)*

**Apa yang terjadi.** Lima belas pengguna tiruan memanggil `POST /masuk` dalam
rentang beberapa milidetik yang sama. Sebagian di antaranya dijawab **HTTP
500**, dan `storage/logs/laravel.log` memuat:

```
SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock
  ... app/Services/Audit/AuditLogger.php:73
```

**Mengapa.** Setiap login menulis satu baris audit log, dan `AuditLogger::log()`
merantainya ke baris sebelumnya dengan:

```php
AuditLog::query()->orderByDesc('id')->lockForUpdate()->first(['id', 'row_hash']);
```

`ORDER BY id DESC LIMIT 1 FOR UPDATE` mengambil *next-key lock* pada ujung
indeks. Dua transaksi yang melakukannya bersamaan dapat saling menunggu, dan
MySQL membunuh salah satunya. Penguncian itu sendiri **benar dan diperlukan**
— tanpanya dua permintaan bersamaan akan mencabangkan rantai hash, yang justru
hal yang dijaga ADR-005.

Akibat yang lebih luas: **setiap penulisan yang diaudit di seluruh aplikasi
menunggu pada satu baris yang sama.** Pada beban yang diuji di sini ia tidak
menjadi sumbat (lihat §6), tetapi ia adalah titik serialisasi global, dan ia
akan menjadi sumbat pertama ketika beban tulis naik.

**Mengapa tidak diperbaiki dalam sprint ini.** Percobaan ulang **tidak dapat**
diletakkan di dalam `AuditLogger`. Ia hampir selalu dipanggil **di dalam**
transaksi pemanggilnya (mis. `ComplaintService::transition()`), dan korban
deadlock dibatalkan MySQL **seluruh transaksinya** — jadi yang harus diulang
adalah transaksi terluar, bukan bagian audit-nya. Itu perubahan di batas
layanan/permintaan yang dapat mengubah invarian modul pengaduan yang baru
selesai di sprint yang sama. Memperbaikinya tergesa-gesa di akhir sprint
stabilisasi adalah cara yang baik untuk menukar satu bug yang terlihat dengan
satu bug yang tidak.

**Rekomendasi, berurutan dari yang paling murah:**

1. **Percobaan ulang di tingkat transaksi terluar.** `DB::transaction($cb, 3)`
   pada layanan yang menulis (bukan di `AuditLogger`), sehingga seluruh
   transaksi diulang. Perlu dipastikan setiap `$cb` **idempoten**.
2. **Middleware yang mengulang permintaan tulis** yang gagal dengan 40001.
   Lebih luas cakupannya, tetapi hanya aman untuk permintaan yang seluruh
   efeknya ada di dalam satu transaksi.
3. **Lepaskan rantai dari penulisan permintaan**: tulis baris audit tanpa
   `prev_hash`, lalu rantai dibentuk pekerjaan latar belakang tunggal. Ini
   menghapus titik serialisasinya sama sekali, tetapi memindahkan jaminan
   keutuhan dari "saat ditulis" ke "beberapa saat kemudian" — perubahan sifat
   bukti, dan karena itu menuntut entri KEP sendiri.

**Mitigasi sementara yang sudah ada:** uji beban menyebar waktu login antar VU
(`tests/Load/pustaka.js`), dan itu diberi keterangan terbuka bahwa ia menutup
kerumunan buatan pada detik nol, **bukan** menutup temuan ini.

**Cara ini ditutup (KEP-020).** Tidak dengan salah satu dari ketiga arah di
atas, dan alasannya ada pada mekanismenya: gap lock InnoDB **saling
kompatibel**, sehingga dua penyisipan audit saja sudah cukup membentuk
deadlock lewat insert-intention lock — tanpa sumber daya ketiga. Yang dipasang
adalah satu **baris mutex** (tabel `audit_chain_lock`, satu baris, dikunci
dengan kesetaraan kunci primer sehingga tidak mengambil gap lock) yang dikunci
**sebelum** ujung rantai dibaca. Itu menghapus sebabnya, bukan gejalanya:
terukur 5 dari 6 gagal tanpanya, 6 dari 6 berhasil dengannya. Arah 1 ditolak
karena ia mengobati gejala di 32 pemanggilan transaksi pada 13 layanan dan
penulis layanan berikutnya tidak akan tahu harus menambahkannya; arah 3
ditolak karena ia memindahkan jaminan keutuhan dari "saat ditulis" ke
"beberapa saat kemudian". Dijaga `tests/Feature/Audit/AuditChainConcurrencyTest`.

---

### Temuan 2 — kolom `changes` tidak pernah masuk ke rantai hash audit *(DIPERBAIKI sesudahnya — KEP-020)*

**Apa yang terjadi.** Penyemai volume menghitung rantai hash-nya sendiri, dan
`sid:verify-audit-chain` menyatakan rantainya rusak pada baris pertama hasil
penyemaian. Selisihnya ternyata tepat satu medan: `changes`.

**Mengapa.** `AuditLog::computeHash()` memakai `$this->changes`. Di dalam
kelas model, nama itu **tidak** mengenai kolom `changes`: ia mengenai properti
`protected $changes` milik
`Illuminate\Database\Eloquent\Concerns\HasAttributes` — daftar atribut yang
berubah setelah `save()`, yang pada baris yang baru dibaca dari basis data
selalu `[]`. Dari **luar** kelas, `$entry->changes` justru mengembalikan isi
kolomnya, karena properti protected tidak terjangkau dan PHP jatuh ke `__get()`.
Dua nilai berbeda untuk satu nama — dan itulah yang menyembunyikannya selama
tujuh sprint.

**Akibatnya.** Nilai lama/baru data penduduk di kolom `changes` dapat diubah
langsung di basis data **tanpa merusak rantai**, dan
`sid:verify-audit-chain` akan tetap melaporkan "rantai utuh". Justru kolom
itulah yang berisi datanya; sisanya hanya menyebutkan siapa dan kapan. Hak
akses MySQL (`sid:harden-audit-log`) masih menahan `UPDATE` lewat akun
aplikasi, jadi ini bukan lubang yang terbuka sendiri — tetapi ia menghapus
lapis kedua yang menjadi seluruh alasan rantai hash itu ada.

**Mengapa tidak diperbaiki dalam sprint ini.** Memperbaiki `computeHash()`
membatalkan `row_hash` **seluruh** baris yang sudah ada, sehingga menuntut
keputusan tentang cara memperlakukan baris lama: rantai kedua yang dimulai
dari titik potong bertanda, atau penghitungan ulang yang ditandatangani. Itu
keputusan tentang integritas **bukti** (ADR-005, RULE-12, docs/08 §9), bukan
pekerjaan uji beban, dan ia pantas mendapat entri KEP sendiri.

**Rekomendasi:** masukkan ke laporan uji penetrasi Sprint 7 sebagai temuan
integritas, lalu perbaiki dengan:
`'changes' => $this->attributes['changes'] ?? null` (atau
`$this->getAttribute('changes')`) **plus** satu kolom `hash_version` pada
`audit_logs`, sehingga verifikasi dapat memeriksa baris lama dengan rumus lama
dan baris baru dengan rumus baru — tanpa pernah menghitung ulang baris yang
sudah ada. Satu uji regresi yang mengubah `changes` di basis data lalu
menuntut `verifyChain()` gagal adalah bagian wajibnya.

**Catatan untuk siapa pun yang menyentuh `VolumeSeeder`:** penyemai sengaja
**mengikuti perilaku nyata** `computeHash()` (`'changes' => []`), bukan
perilaku yang seharusnya, karena verifikasinya memakai `computeHash()` yang
sama. Begitu temuan ini diperbaiki, `VolumeSeeder::chainHash()` wajib diubah
bersamanya — dan `VolumeSeederTest::rantai_hash_audit_log_tetap_utuh...` akan
gagal bila lupa.

**Cara ini ditutup (KEP-020), dan cacat KEEMPAT yang ditemukan saat
menutupnya.** Rumus hash kini satu-satunya, `AuditLog::hashFromRow()`, yang
membaca kolomnya lewat `getAttributes()` sehingga `changes` benar-benar ikut
dihitung; `VolumeSeeder::chainHash()` **dihapus** dan penyemai memanggil rumus
yang sama — salinan rumus itulah yang semula membuat keduanya menyimpang tanpa
ada yang menyadarinya. Kolom baru `hash_version` memisahkan baris lama dari
baris baru, karena memperbaiki rumus membatalkan `row_hash` seluruh baris yang
sudah ada dan baris audit log **tidak boleh** dihitung ulang.

Saat itulah cacat keempat muncul, dan arahnya **berlawanan** dengan temuan ini:
kolom `context` tidak terbayang oleh properti `$changes`, jadi ia memang sudah
ikut dihitung — tetapi MySQL menyimpan kolom JSON dalam bentuk binernya
sendiri dan mengembalikan kuncinya **terurut** (panjang kunci, lalu bytewise)
pada setiap tingkat sarang, sementara rumus lama meng-encode menurut urutan
tulis. Akibatnya `sid:verify-audit-chain` melaporkan "RANTAI RUSAK" atas baris
yang tidak pernah disentuh siapa pun: pada basis data pengembangan, baris
`letter.issued` id=7 — hash tersimpannya cocok persis bila kuncinya disusun
`nomor_surat, jenis_surat, tiket`, yaitu urutan tulisnya. Temuan ini adalah
alarm yang diam; yang itu adalah alarm yang berbunyi atas data jujur, dan
alarm integritas bukti yang berbunyi palsu akan diabaikan. Keduanya **harus**
ditutup bersama: memperbaiki yang ini sendirian akan memutus rantai pada
hampir setiap baris berkunci banyak. Rumus baru karena itu juga
**mengurutkan** kunci objek sebelum meng-encode (senarai tetap pada
urutannya, karena di sana urutan bermakna dan MySQL mempertahankannya).
Keduanya dijaga uji sendiri: `AuditLogTest::perubahan_kolom_changes_terdeteksi`
(yang sebelum ini **lulus padahal seharusnya gagal**) dan
`AuditLogTest::urutan_kunci_json_tidak_merusak_rantai`.

---

### Temuan 3 — halaman Laporan melewati NFR-PERF-01 pada volume sasaran *(DIPERBAIKI — KEP-019)*

**Apa yang terjadi.** `ReportService::population()` terukur **755 ms** pada
20.000 penduduk. NFR-PERF-01 menetapkan p95 baca ≤ 500 ms, jadi halaman itu
melewati batasnya **sendirian**, sebelum satu pun pengguna lain menekan server.

**Mengapa.** Bukan satu kueri yang rumit, melainkan **enam kali membaca tabel
yang sama**. Halaman Laporan menampilkan rekap per wilayah dan per lima
kategori; setiap rekap menyaring `status='aktif' AND deleted_at IS NULL` lalu
membaca **satu kolom lain** per baris. Indeks `idx_residents_status` hanya
memuat kedua kolom penyaring itu, sehingga kolom kategorinya diambil satu per
satu dari indeks berkluster — 19.600 pencarian baris acak, per rekap, dengan
kunci primer CHAR(36). Rekap per wilayah bahkan menghidrasi 20.000 objek
Eloquent hanya untuk menambahkannya ke tiga penghitung: 81 ms SQL, ± 290 ms
PHP.

**Mengapa tidak pernah terlihat sampai sekarang:** basis data pengembangan
berisi 500 penduduk (docs/09 §3), dan pada 500 baris keenam pembacaan itu
masing-masing 2 ms. Halaman ini tidak pernah melambat di mana pun yang dilihat
orang — ia hanya akan melambat di desa, setelah impor data kependudukan yang
sebenarnya. Inilah persisnya kelas bug yang uji volume ada untuk menemukannya.

**Yang diperbaiki** (rinciannya di **KEP-019**):

1. `idx_residents_status` dilebarkan menjadi indeks penutup
   (migrasi `2026_01_08_000100`).
2. `ResidentRepository::recapByCategories()` — kelima rekap kategori dalam
   **satu** kueri, dijumlahkan per kolom di PHP.
3. `ResidentRepository::recapByRegion()` — `GROUP BY region_id, gender`
   (± 24 baris), bukan memuat seluruh penduduk.

| | sebelum | sesudah |
|---|---|---|
| rekap per wilayah | 370 ms | 10 ms |
| rekap lima kategori | 385 ms | 22 ms |
| `ReportService::population()` | **755 ms** | **43 ms** |
| halaman `/laporan`, waktu basis data | 495 ms | 66 ms |
| halaman `/laporan`, jumlah kueri | 28 | 22 |

Indeks penutup **terpisah** sudah dicoba lebih dulu dan ditolak karena
diukur: ia memang membuat kelima kueri lama menjadi 10–22 ms, tetapi hanya
dengan `FORCE INDEX` — pengoptimasi MySQL tetap memilih indeks yang lebih
sempit. Alasan lengkapnya di KEP-019.

Dijaga uji: `tests/Feature/Report/ReportRecapQueryTest.php` (jumlah kueri,
tidak ada hidrasi baris, daftar putih kolom, dan **susunan kolom indeksnya**).

---

### Temuan 4 — `COUNT(*)` paginator adalah satu-satunya kueri audit log yang mahal

**Apa yang terjadi.** Seluruh kueri `audit_logs` yang tersaring selesai di
bawah 1 ms pada 1,5 juta baris. Satu-satunya yang mahal adalah `COUNT(*)`
atas seluruh tabel: **± 95 ms**, dan ia **tidak dapat dibuat lebih cepat**
karena ia memang harus menyentuh seluruh tabel.

**Mengapa ini penting sekarang, padahal halamannya belum ada.** Halaman
`pengaturan/log-aktivitas` masih berupa placeholder. Begitu ia dibangun dengan
`paginate()` bawaan Laravel, paginator itu akan memanggil `COUNT(*)` pada
**setiap** pembukaan halaman — dan 95 ms itu akan menjadi biaya tetap satu
halaman yang hanya dibaca Admin Desa.

**Rekomendasi:** bangun halaman itu dengan **`cursorPaginate()`**, bukan
`paginate()` — sama seperti yang sudah dilakukan
`ResidentRepository::search()`. Log aktivitas adalah daftar yang dibaca dari
atas ke bawah; nomor halaman dan jumlah total baris tidak pernah dibutuhkan
siapa pun di sana, dan keduanya adalah satu-satunya alasan `COUNT(*)` ada.
Bila jumlah total benar-benar diminta, ia dapat ditampilkan sebagai angka yang
di-cache harian, bukan dihitung ulang per pembukaan.

---

### Temuan 5 — `sid:verify-audit-chain` tumbuh KUADRATIK, karena `lazy()` memakai OFFSET *(DIPERBAIKI sesudahnya — KEP-020)*

**Apa yang terjadi.** Verifikasi rantai hash atas 1,5 juta baris selesai dalam
**± 15,5 menit** dan hasilnya benar (rantai utuh, 1.500.002 baris diperiksa).
Tetapi waktunya tidak tumbuh sebanding dengan jumlah barisnya:

| Baris diperiksa | Waktu | Lipat dari baris sebelumnya |
|---|---|---|
| 100.000 | 7,5 s | — |
| 200.000 | 24,1 s | 3,2× untuk 2× data |
| 400.000 | 84,2 s | 3,5× untuk 2× data |
| 800.000 | 299,4 s | 3,6× untuk 2× data |
| 1.500.002 | ± 930 s | — |

Setiap kali datanya dua kali lebih banyak, waktunya **tiga setengah kali**
lebih lama. Itu bukan konstanta yang buruk; itu bentuk kurva yang salah.

**Mengapa.** `AuditLogger::verifyChain()` memakai
`AuditLog::query()->orderBy('id')->lazy(500)`. `lazy()` memecah kueri dengan
**LIMIT/OFFSET**, bukan dengan kunci (`lazyById()` yang melakukan itu). Pada
potongan terakhir, MySQL harus **melewati 1,5 juta baris** untuk mencapai
`OFFSET 1500000` — dan ia melakukannya lagi untuk setiap potongan. Jumlah
baris yang dilewati seluruhnya tumbuh dengan kuadrat jumlah potongan.

Yang membuat ini pantas dicatat: perbedaan antara `lazy()` dan `lazyById()`
berupa satu kata, keduanya tampak sama di layar, dan keduanya benar — hanya
salah satunya yang masih benar pada satu juta baris.

**Rekomendasi:**

1. **`lazy()` → `lazyById()`** (atau `->toBase()->orderBy('id')->cursor()`).
   Itu menghapus OFFSET-nya sama sekali dan membuat waktunya lurus terhadap
   jumlah baris.
2. Sekalian **jangan hidrasi modelnya**: hash dapat dihitung dari array
   mentah, dan 1,5 juta objek Eloquent beserta cast JSON dua kolomnya adalah
   biaya kedua di sini. Pola yang sama persis dengan Temuan 3.
3. Sampai keduanya dilakukan: tetap **mingguan di luar jam kerja** seperti
   yang sudah tertulis di ADR-005, pakai `--limit` untuk pemeriksaan harian
   yang murah, dan **jangan** memasangnya sinkron di jalur permintaan mana pun.

Perintah ini **tidak diperbaiki dalam sprint ini** karena ia bukan bagian
lingkup Bagian 7 dan perubahannya menyentuh `AuditLogger` yang sedang juga
menjadi pokok Temuan 1 dan 2 — ketiganya sebaiknya dikerjakan bersama,
dengan uji regresi yang memeriksa keduanya: hasil verifikasi tetap sama, dan
waktunya lurus.

**Cara ini ditutup (KEP-020).** Ketiganya memang dikerjakan bersama.
`verifyChain()` kini memakai `lazyById(500)` — keyset, bukan OFFSET — dan
**tidak lagi menghidrasi model**: hash dihitung dari baris mentah
`DB::table('audit_logs')->select([...])`, sehingga 1,5 juta objek Eloquent
dengan cast JSON pada dua kolom tidak lagi dibuat. Yang berubah adalah bentuk
kurvanya, bukan konstantanya. Diukur ulang pada `sid_desa_volume` dengan
kedua lingkaran meng-hash setiap baris agar sebanding:

| baris | lama `lazy()` | baru `lazyById()` | lipat |
|---|---|---|---|
| 100.000 | 7,6 s | 0,7 s | 10,4× |
| 200.000 | 25,2 s | 1,7 s | 15,0× |
| 400.000 | 86,5 s | 2,9 s | 30,4× |
| 800.000 | 307,5 s | 7,2 s | 42,6× |
| 1.500.081 | ± 930 s (angka tabel di atas) | 14,8 s | ± 63× |

Angka "lama" dari alat ukur itu cocok dengan tabel di awal butir ini dalam
2–3% (7,6 melawan 7,5 s pada 100.000 baris), sehingga kedua kolom sebanding;
baris 1,5 juta memakai angka pengukuran ini karena mengulangnya memerlukan
± 15 menit untuk sesuatu yang sudah terukur. 15× data kini menjadi 21× waktu,
bukan 122×.

---

### Temuan 6 — hal-hal yang diperiksa dan ternyata BAIK

Dicatat karena ketiadaan temuan juga hasil, dan karena ketiganya pernah
diragukan di dokumen lain:

1. **Tidak ada N+1 pada halaman mana pun yang diukur.** Jumlah kueri per
   halaman tetap (8–31) dan tidak bertambah seiring volume; kueri yang
   berulang paling banyak **3×** per halaman, dan itu kueri pengaturan, bukan
   kueri per baris. `Model::preventLazyLoading()` yang menyala di
   pengembangan dan pengujian tampaknya memang bekerja sebagai pencegahnya.
2. **Keputusan "tanpa partisi" pada `audit_logs` terbukti benar.** Migrasinya
   beralasan "± 20.000 baris/bulan pada satu desa masih nyaman tanpa
   partisi"; pada 1,5 juta baris — ± 75 bulan — seluruh kueri tersaringnya
   masih di bawah 1 ms.
3. **Tiadanya `pg_trgm` (KEP-002) dapat ditanggung.** Kriteria `LIKE '%…%'` ≤
   200 ms lulus dengan margin ± 60×. Bila pilihan MySQL-vs-PostgreSQL dibuka
   lagi, angka inilah yang relevan: pada skala satu desa, pencarian nama
   bukan alasan untuk berpindah.

---

## 8. Cara mengulang pengukuran ini

Seluruhnya ada di `tests/Load/README.md`. Ringkasnya:

```bash
npm run build
php artisan sid:seed-volume --segar
DB_DATABASE=sid_desa_volume php tests/Load/siapkan-data-k6.php

PHP_CLI_SERVER_WORKERS=32 DB_DATABASE=sid_desa_volume APP_DEBUG=false \
CACHE_STORE=array php -d opcache.enable_cli=1 -d opcache.validate_timestamps=0 \
  -S 127.0.0.1:8111 -t public tests/Load/router.php

k6 run tests/Load/beban-campuran.js            # 30 menit
k6 run tests/Load/tekanan.js                   # ± 8 menit
DB_DATABASE=sid_desa_volume php tests/Load/ukur-kueri.php --ulang=20

php artisan sid:seed-volume --bersihkan
```

Pengukuran ini **harus diulang** sebelum go-live di lingkungan staging yang
sebenarnya (php-fpm + Nginx + Redis, docs/10 §3), karena §3 di atas
menjelaskan mengapa angka di dokumen ini tidak dapat dipindahkan begitu saja.
Yang dapat dipindahkan adalah **temuannya**: kueri yang buruk tetap buruk di
perangkat apa pun.
