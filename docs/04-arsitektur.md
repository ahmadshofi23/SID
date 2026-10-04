# 04 — Arsitektur Sistem
**Proyek:** Sistem Informasi Desa (SID) · **Versi:** 2.0 · **Penyusun:** Software Architect
**Lingkup:** satu desa (KEP-001) · **Basis data:** MySQL 8.0 (KEP-002)

> **Perubahan dari v1.0:** lingkup menjadi **satu desa** dan basis data menjadi
> **MySQL 8**. Bagian §2 (teknologi) dan §4 (tenancy) ditulis ulang; ADR-001, ADR-004,
> dan ADR-007 disesuaikan. Keputusan & alasannya ada di [00 Log Keputusan](00-log-keputusan.md).

---

## 1. Tujuan & Keputusan Arsitektur Utama

Arsitektur dipilih berdasarkan empat kekuatan pendorong (*architectural drivers*):

1. **Tim kecil & anggaran terbatas** → hindari kompleksitas operasional.
2. **Data pribadi sensitif** → isolasi & otorisasi harus sederhana untuk diaudit.
3. **Pengguna di jaringan lemah** → *payload* kecil, tahan latensi.
4. **Harus bisa diserahkan ke desa/kabupaten** → mudah dipasang & dirawat orang lain.

**Keputusan:** **Modular monolith** dengan *queue worker* terpisah — **bukan**
mikroservis. Mikroservis akan menambah biaya operasional (orkestrasi, observabilitas,
konsistensi lintas layanan) tanpa manfaat nyata pada skala satu desa sampai ratusan desa.

---

## 2. Pilihan Teknologi

> **Status:** stack di bawah sudah **ditetapkan** (KEP-004). Faktor penentunya adalah
> ketersediaan hosting di Indonesia dan siapa yang merawat sistem setelah serah terima.

### 2.1 Stack yang dipakai

| Lapis | Teknologi | Alasan |
|-------|-----------|--------|
| Bahasa/Framework | **PHP 8.3 + Laravel 11** | Ekosistem pemerintahan desa di Indonesia mayoritas PHP; mudah mencari pengembang & penyedia hosting; *batteries included* (queue, auth, migration, policy, scheduler) |
| Basis data | **MySQL 8.0** (KEP-002) | Paling mudah di-*hosting* di Indonesia; kolam SDM pemeliharaan paling luas; InnoDB mendukung transaksi & `FOR UPDATE` yang dibutuhkan penomoran surat. Keterbatasannya (tanpa RLS, tanpa *partial index*, tanpa `pg_trgm`) sudah punya padanan — lihat 05 §9 |
| Frontend panel | **Inertia.js + React + TypeScript + Tailwind** | Satu bahasa tipe aman di frontend, tanpa beban membangun API publik ganda untuk kebutuhan internal |
| Frontend portal publik | **Blade + sedikit JS (SSR)** | SEO & LCP terbaik, *payload* paling kecil, tanpa hidrasi berat |
| Cache & Queue | **Redis 7** | *Cache*, *session*, *queue*, *rate limit*, *lock* penomoran surat |
| Pencarian | Kolom nama ternormalisasi + `LIKE`, plus `FULLTEXT` InnoDB | Memadai pada ± 5.000 penduduk (basis data < 1 GB, seluruhnya di memori — lihat 05 §8). **Tanpa toleransi salah ketik** — konsekuensi yang diterima di KEP-002 |
| Penyimpanan berkas | **S3-kompatibel** (MinIO *on-prem* / object storage awan) | Terpisah dari server aplikasi; mudah dicadangkan |
| PDF | **Gotenberg** (Chromium *headless*) atau wkhtmltopdf | Template HTML/CSS sama dengan tampilan layar; dukungan F4 & kop |
| Autentikasi | Laravel Fortify + TOTP | Teruji, *rate limit* bawaan |
| Observabilitas | Logging JSON + Prometheus/Grafana + Sentry (atau GlitchTip *self-hosted*) | Murah, dapat *on-prem* |

### 2.2 Konsekuensi memilih MySQL (yang harus dikompensasi di kode)

Karena MySQL tidak punya Row-Level Security, **tidak ada jaring pengaman di tingkat
basis data** untuk dua pembatasan akses berikut:

| Pembatasan | Satu-satunya penegak | Kompensasi wajib |
|-----------|----------------------|------------------|
| Kepala Dusun hanya melihat wilayahnya | Kode aplikasi (`user_roles.region_id`) | Semua kueri wajib lewat satu lapis *repository* (§3.3); uji otorisasi 09 §4.3 **wajib** |
| Warga hanya melihat data keluarganya | Kode aplikasi (`users.resident_id` → `family_id`) | Sama seperti di atas |

Dengan lingkup satu desa (KEP-001), risiko terbesar dari ketiadaan RLS —
**kebocoran antar-desa** — tidak berlaku. Yang tersisa adalah dua pembatasan di atas,
yang pada PostgreSQL pun umumnya ditegakkan di lapis aplikasi.

Keunikan bersyarat (NIK unik hanya untuk penduduk aktif, satu kepala keluarga per KK)
**tetap ditegakkan basis data** lewat teknik kolom *generated* — lihat 05 §2.2.

### 2.3 Yang **dihindari** pada MVP (dan alasannya)
- **Mikroservis / Kubernetes** — biaya operasional tidak sebanding.
- **Multi-tenancy** — tidak dibutuhkan (KEP-001). Jalur retrofit terdokumentasi di 05 §10.
- **Elasticsearch** — menambah komponen *stateful* untuk masalah yang dapat ditangani MySQL pada skala ini.
- **GraphQL** — konsumen API hanya frontend sendiri; REST + OpenAPI lebih mudah diaudit.
- **SPA murni untuk portal publik** — merusak SEO & kinerja di 3G.
- **Multi-cloud / serverless** — menyulitkan opsi *on-premise* kabupaten.

---

## 3. Diagram Arsitektur

### 3.1 C4 Level 1 — Konteks Sistem

```
┌────────────┐   ┌────────────────┐   ┌──────────────┐   ┌──────────────┐
│   Warga    │   │ Perangkat Desa │   │ Kadus / RT   │   │  Pengawas    │
│  (browser) │   │   (browser)    │   │   (ponsel)   │   │ (BPD/Camat)  │
└──────┬─────┘   └────────┬───────┘   └──────┬───────┘   └──────┬───────┘
       └──────────────────┴──────────────────┴──────────────────┘
                                   │ HTTPS
                     ┌─────────────▼──────────────┐
                     │  SISTEM INFORMASI DESA     │
                     │  (portal · panel · API)    │
                     └──┬───────┬───────┬─────────┘
                        │       │       │
          ┌─────────────┘       │       └──────────────┐
          ▼                     ▼                      ▼
  ┌───────────────┐   ┌──────────────────┐   ┌─────────────────────┐
  │ SMTP / WA GW  │   │ Object Storage   │   │ (Opsional, Fase 3)  │
  │ (notifikasi)  │   │ (berkas & arsip) │   │ WS Dukcapil / SIPD  │
  └───────────────┘   └──────────────────┘   └─────────────────────┘
```

### 3.2 C4 Level 2 — Kontainer

```
                           ┌───────────────────────────┐
         Internet ────────►│  CDN / WAF (opsional)     │
                           └─────────────┬─────────────┘
                                         ▼
                           ┌───────────────────────────┐
                           │  Reverse Proxy (Nginx)    │
                           │  TLS · gzip/brotli ·      │
                           │  rate limit · static      │
                           └───┬───────────────────┬───┘
                               ▼                   ▼
            ┌──────────────────────────┐  ┌──────────────────────────┐
            │  App Web (PHP-FPM)       │  │  Queue Worker            │
            │  · Portal publik (SSR)   │  │  · impor data            │
            │  · Panel admin (Inertia) │  │  · render PDF            │
            │  · REST API /api/v1      │  │  · kirim notifikasi      │
            │  · Scheduler (cron)      │  │  · ekspor laporan besar  │
            └───┬──────────┬───────┬───┘  └───┬──────────┬───────────┘
                │          │       │          │          │
      ┌─────────▼──┐  ┌────▼────┐  │  ┌───────▼──────┐  ┌▼────────────┐
      │  MySQL 8   │  │  Redis  │  │  │  Gotenberg   │  │ S3 Storage  │
      │  (InnoDB,  │  │ cache · │  │  │  (PDF render)│  │ (berkas)    │
      │   binlog)  │  │ queue · │  │  └──────────────┘  └─────────────┘
      └────────────┘  │ lock    │  │
                      └─────────┘  └──► SMTP / WhatsApp Gateway
```

### 3.3 C4 Level 3 — Modul di dalam aplikasi

```
app/
├── Modules/
│   ├── Identity/        ← pengguna, peran, izin, 2FA, sesi
│   ├── Village/         ← identitas desa, wilayah, perangkat, pengaturan
│   ├── Population/      ← penduduk, keluarga, mutasi, wilayah, statistik
│   ├── Correspondence/  ← jenis surat, template, pengajuan, penomoran, PDF
│   ├── Complaint/       ← pengaduan, penugasan, tindak lanjut
│   ├── PublicPortal/    ← berita, pengumuman, agenda, halaman statis
│   ├── Finance/         ← APBDes (Fase 2)
│   ├── Asset/           ← aset desa (Fase 2)
│   ├── SocialAid/       ← bantuan sosial (Fase 2)
│   ├── Reporting/       ← laporan, ekspor, buku administrasi
│   └── Platform/        ← audit log, notifikasi, berkas, pencarian, referensi
└── Shared/              ← kontrak, value object, util lintas modul
```

**Aturan ketergantungan antar-modul (ditegakkan lewat *static analysis* di CI):**
1. Modul **tidak boleh** mengimpor model internal modul lain. Komunikasi hanya melalui
   *service interface* publik di `Shared/Contracts` atau *domain event*.
2. `Platform` dan `Village` boleh dipakai semua modul; tidak boleh sebaliknya.
3. Tidak ada ketergantungan melingkar.

Tujuannya: bila suatu saat modul perlu dipisah menjadi layanan sendiri, batasnya
sudah jelas — **tanpa** menanggung biaya mikroservis sekarang.

---

## 4. Lingkup Data: Satu Desa (Single-Tenant)

**Keputusan (KEP-001):** satu instalasi = satu desa. **Tidak ada `tenant_id`** di
skema basis data, tidak ada Row-Level Security, tidak ada middleware tenant.

| Pendekatan | Isolasi | Biaya | Keputusan |
|------------|---------|-------|-----------|
| **Satu basis data per desa (single-tenant)** | **Mutlak** — tidak ada data desa lain | Linier per desa, tetapi rendah | **✓ dipilih** |
| Shared + `tenant_id` + RLS | Baik bila RLS benar | Rendah | ✗ tidak dibutuhkan sekarang |
| Shared + `tenant_id` tanpa RLS | Bergantung disiplin kode | Rendah | ✗ terlalu berisiko untuk data NIK |

**Konsekuensi positif:** kelas bug paling berbahaya pada sistem multi-tenant —
kebocoran data antar-organisasi — **tidak dapat terjadi**, karena data desa lain
tidak ada di basis data ini.

### 4.1 Pembatasan akses yang TETAP harus ditegakkan kode

Hilangnya tenancy **tidak** menghilangkan kebutuhan otorisasi. Dua pembatasan berikut
adalah risiko utama yang tersisa, dan keduanya hanya ditegakkan oleh kode aplikasi:

| Pembatasan | Sumber data | Risiko bila lupa |
|-----------|-------------|------------------|
| **Kepala Dusun** hanya wilayahnya | `user_roles.region_id` + `regions.path` | Melihat data penduduk seluruh desa |
| **Warga** hanya dirinya & satu KK | `users.resident_id` → `residents.family_id` | Warga melihat NIK warga lain — pelanggaran UU PDP |

**Penegakan berlapis (menggantikan RLS):**
1. **Lapis *repository*** — satu kelas per entitas yang **selalu** menerima konteks
   pengguna dan menerapkan pembatasan wilayah/keluarga. Kontroler **dilarang**
   membangun kueri sendiri ke `residents`, `families`, `letter_requests`, `complaints`.
2. **Lapis *policy*** — pemeriksaan per objek sebelum ditampilkan (08 §4 Lapis 2).
3. **Lapis analisis statis** — aturan CI yang menggagalkan *build* bila ada kueri
   ke empat tabel di atas dari luar lapis *repository*.
4. **Lapis uji** — `tests/Authorization/` menjalankan matriks (peran × endpoint ×
   kepemilikan objek). **Tidak boleh di-*skip* dengan alasan apa pun** (09 §4.3).

> **Mengapa lapis 1 bersifat wajib arsitektural:** ia juga satu-satunya tempat yang
> perlu diubah bila kelak sistem harus melayani beberapa desa (retrofit di 05 §10).
> Membiarkan kueri tersebar di kontroler membuat retrofit itu berubah dari
> 2 hari menjadi berminggu-minggu.

### 4.2 Bila kelak perlu melayani beberapa desa
Dua jalur, dengan rekomendasi yang berbeda menurut jumlah desa:

| Jumlah desa | Jalur yang disarankan | Catatan |
|-------------|----------------------|---------|
| ≤ ± 10 | **Satu instalasi per desa** | Tanpa perubahan kode; isolasi mutlak; ± Rp 250–400 rb/bln per desa (10 §11) |
| > ± 10 | Retrofit multi-tenancy | 16–19 hari-orang; rinci di 05 §10 |

---

## 5. Keputusan Desain Penting (ADR Ringkas)

### ADR-001 — Penomoran surat bebas duplikat & bebas celah
**Masalah:** dua petugas menyetujui surat bersamaan → nomor ganda atau terlewat.
**Keputusan:** nomor diterbitkan **hanya** pada transisi ke `DITERBITKAN`, di dalam
transaksi basis data, menggunakan tabel penghitung dengan **pessimistic lock**:

```sql
START TRANSACTION;

-- Pastikan baris penghitung ADA sebelum dikunci (penting di MySQL — lihat di bawah)
INSERT INTO letter_counters (letter_type_id, year, last_number)
VALUES (:lt, :y, 0)
ON DUPLICATE KEY UPDATE letter_type_id = letter_type_id;

SELECT last_number FROM letter_counters
 WHERE letter_type_id = :lt AND year = :y
 FOR UPDATE;                      -- serialisasi di sini (InnoDB row lock)

UPDATE letter_counters SET last_number = last_number + 1
 WHERE letter_type_id = :lt AND year = :y;

INSERT INTO letters (..., letter_number = :formatted) ...;
COMMIT;
```
**Mengapa bukan `AUTO_INCREMENT`?** `AUTO_INCREMENT` tidak transaksional — bila
transaksi *rollback*, nomor hilang dan deret berlubang. Audit administrasi desa tidak
menerima nomor yang terlewat.

**Jebakan khusus MySQL:** `SELECT … FOR UPDATE` pada baris yang **belum ada** tidak
mengunci apa pun. Tanpa `INSERT … ON DUPLICATE KEY` lebih dulu, dua permintaan
pertama di awal tahun dapat sama-sama lolos dan menghasilkan nomor ganda. Ini kasus
uji wajib — 09 §5 TC-SRT-08 varian (b).

**Jaring pengaman kedua:** `UNIQUE (letter_type_id, issued_year, sequence_number)` di
tabel `letters` (05 §3.3) menolak nomor ganda walau logika di atas keliru.

**Konsekuensi:** ada titik serialisasi per (jenis surat, tahun). Dengan volume desa
(< 100 surat/hari) ini tidak menjadi masalah kinerja. **Wajib ada uji konkurensi**
(dokumen 09, TC-SRT-08).

### ADR-002 — Formulir dinamis memakai kolom JSON + skema tervalidasi
**Keputusan:** definisi *field* per jenis surat disimpan sebagai **JSON Schema** di
`letter_types.form_schema`; jawaban pemohon disimpan di `letter_requests.form_data`
(tipe `JSON` MySQL).
**Alasan:** petugas dapat menambah jenis surat tanpa migrasi basis data (BR-12) —
inilah yang memungkinkan katalog 22 surat di dokumen 13 disunting sendiri oleh desa.
**Konsekuensi:** validasi **wajib** dilakukan terhadap skema di sisi server (MySQL
tidak memvalidasi isi JSON terhadap skema); *field* JSON yang perlu dicari/diindeks
memerlukan kolom *generated*; pelaporan atas *field* dinamis lebih rumit → sediakan
tabel ringkasan untuk laporan yang sering dipakai.

### ADR-003 — PDF dirender dari HTML, bukan dibangun secara imperatif
**Keputusan:** template surat adalah HTML/CSS yang dirender Chromium *headless*
(Gotenberg) sebagai layanan terpisah.
**Alasan:** petugas dapat menyunting tampilan surat; *preview* di layar = hasil cetak.
**Konsekuensi:** satu kontainer tambahan; render dilakukan di *queue* (NFR-PERF-05);
PDF terbit **disimpan permanen** (bukan dirender ulang) agar arsip tidak berubah
saat template diperbarui — ini penting secara hukum.

### ADR-004 — Enkripsi tingkat kolom untuk NIK & nomor KK
**Keputusan:** NIK dan nomor KK disimpan terenkripsi **di lapis aplikasi**
(AES-256-GCM, kunci di *secret manager*) pada kolom `VARBINARY`, **ditambah** kolom
`nik_hash` (HMAC-SHA256 dengan *pepper*) untuk pencarian persis dan penegakan
keunikan, serta `nik_masked` untuk tampilan.
**Catatan MySQL:** enkripsi dilakukan aplikasi, **bukan** fungsi `AES_ENCRYPT()`
MySQL — fungsi bawaan akan menaruh kunci di dalam teks kueri, sehingga bocor ke
*slow query log*, *binlog*, dan `SHOW PROCESSLIST`.
**Alasan:** bila *dump* basis data bocor, identitas tidak langsung terbaca.
**Konsekuensi:** pencarian NIK hanya **persis** (bukan parsial) — dapat diterima;
rotasi kunci memerlukan prosedur *re-encrypt* terdokumentasi.

### ADR-005 — Audit log *append-only* di tabel terpisah
**Keputusan:** tabel `audit_logs` tanpa izin UPDATE/DELETE bagi pengguna aplikasi
(ditegakkan dengan GRANT basis data), dipartisi bulanan, dengan *hash chain*
(`prev_hash`) agar perubahan di luar aplikasi dapat dideteksi.
**Alasan:** BR-41 & RULE-12 menuntut log yang tidak dapat diubah.

### ADR-006 — Portal publik SSR, panel admin SPA
**Keputusan:** dua pendekatan *rendering* berbeda dalam satu aplikasi.
**Alasan:** portal butuh SEO & LCP cepat di 3G; panel butuh interaktivitas tinggi
tetapi penggunanya sedikit dan terotentikasi.
**Konsekuensi:** dua jalur *build*; pengujian visual harus mencakup keduanya.

### ADR-007 — Statistik dari tabel ringkasan, disegarkan terjadwal
**Keputusan:** agregasi penduduk dihitung ke tabel `population_stats` oleh *job*
aplikasi — setiap jam, dan langsung setelah impor massal atau mutasi (05 §4).
**Alasan:** halaman statistik publik tidak boleh membebani basis data transaksi, dan
**tidak boleh mengakses tabel `residents` secara langsung** — ini sekaligus mengurangi
permukaan kebocoran data pribadi dari endpoint publik.
**Mengapa bukan MySQL EVENT:** *job* di aplikasi masuk *version control*, dapat diuji,
dan kegagalannya terlihat di pemantauan. MySQL EVENT tersembunyi di dalam basis data.
**Konsekuensi:** angka dapat tertinggal maksimum 1 jam → portal menampilkan
"Data per: {{computed_at}}" agar tidak menyesatkan.

### ADR-008 — Penyamaran sel kecil pada statistik publik
**Keputusan:** setiap sel agregat dengan jumlah 1–4 ditampilkan sebagai "< 5".
**Alasan:** mencegah identifikasi ulang individu dari kombinasi filter (BR-21).

---

## 6. Pola Lintas Potongan (*Cross-Cutting*)

| Aspek | Pendekatan |
|-------|-----------|
| **Otorisasi** | *Policy* per entitas + *permission* granular; **satu titik penegakan** di lapis *repository*/service (§4.1), bukan tersebar di kontroler |
| **Validasi** | Objek validasi terpisah per aksi; aturan bisnis kompleks di *domain service*, bukan di kontroler |
| **Transaksi** | Satu *use case* = satu transaksi; efek samping (email, PDF) ditunda lewat *queue* setelah *commit* |
| **Idempotensi** | *Endpoint* tulis menerima `Idempotency-Key`; mencegah duplikasi saat jaringan buruk & pengguna menekan tombol dua kali |
| **Galat** | Format galat seragam (dokumen 06 §4); galat tak terduga dicatat lengkap tetapi pengguna hanya melihat pesan umum + *correlation id* |
| **Berkas** | Unggah → validasi *magic bytes* → nama acak → S3 privat → akses lewat *pre-signed URL* berumur pendek (5 menit) |
| **Notifikasi** | Antrean dengan *retry* eksponensial; log pengiriman; kegagalan tidak menggagalkan transaksi utama |
| **Konfigurasi** | Variabel lingkungan untuk infrastruktur; tabel `settings` untuk aturan bisnis yang diubah desa dari UI |
| **Zona waktu** | Server MySQL & aplikasi berjalan UTC; konversi ke zona waktu desa (WIB/WITA/WIT) dilakukan saat penyajian |
| **Observabilitas** | Log JSON + `correlation_id`; metrik RED (Rate, Errors, Duration) per *endpoint*; peringatan ke saluran operasional |
| **Pekerjaan terjadwal** | Scheduler menjalankan: segarkan statistik, eskalasi SLA, cadangan, pembersihan berkas sementara, deteksi anomali data |

---

## 7. Lingkungan

| Lingkungan | Tujuan | Data | Catatan |
|-----------|--------|------|---------|
| **Local** | Pengembangan | *Seed* sintetis | Docker Compose, satu perintah jalan |
| **CI** | Uji otomatis | *Factory* | Basis data sekali pakai per *job* |
| **Staging** | UAT & pelatihan | **Anonim** (hasil *masking* produksi) | **Dilarang** memakai data warga asli |
| **Production** | Operasional | Nyata | Akses dibatasi, semua akses tercatat |

---

## 8. Kapasitas & Penskalaan

**Asumsi beban satu desa (≈ 5.000 jiwa):**
- Penduduk 5.000 · KK 1.500 · surat 50/hari · pengunjung portal 500/hari.
- Pertumbuhan audit log ≈ 20.000 baris/bulan.

**Perkiraan ukuran (rinci di 05 §8):** basis data **< 1 GB setelah 5 tahun**; berkas
unggahan ≈ 4 GB/tahun (didominasi lampiran foto & arsip PDF).

> Konsekuensi penting: seluruh basis data masuk ke `innodb_buffer_pool` 2 GB, sehingga
> hampir semua kueri dilayani dari memori. Inilah alasan beberapa kekhawatiran kinerja
> (pencarian `LIKE`, laporan tanpa *read replica*) dapat diterima pada skala ini.

**Spesifikasi awal (satu desa):**
| Komponen | Spesifikasi minimum |
|----------|---------------------|
| App server | 2 vCPU · 4 GB RAM |
| MySQL 8 | 2 vCPU · 4 GB RAM · SSD 50 GB (`innodb_buffer_pool_size` 2 GB) |
| Redis | 1 GB RAM |
| Object storage | 100 GB, dapat ditumbuhkan |

**Jalur penskalaan bila tumbuh:** (1) tambah *app instance* di belakang *load
balancer* — aplikasi *stateless*; (2) *read replica* MySQL untuk laporan &
portal; (3) pisahkan *queue worker* ke mesin sendiri; (4) CDN untuk aset & halaman
publik; (5) baru setelah itu pertimbangkan memisahkan modul berat.

**Yang menjadi hambatan lebih dulu** (perlu dipantau): render PDF (CPU),
ekspor laporan besar (memori), dan kueri statistik bila *materialized view* tidak dipakai.

---

## 9. Struktur Repositori

```
sitem-desa/
├── docs/                    ← dokumen perancangan (repositori ini)
├── app/
│   ├── Modules/             ← modul domain (lihat §3.3)
│   ├── Shared/
│   └── Http/                ← middleware, kernel
├── database/
│   ├── migrations/
│   ├── seeders/             ← data referensi + seed sintetis
│   └── factories/
├── resources/
│   ├── js/                  ← panel admin (React + TS)
│   ├── views/               ← portal publik (Blade) + template surat
│   └── css/
├── routes/                  ← web.php · admin.php · api.php · public.php
├── tests/
│   ├── Unit/ · Feature/ · Authorization/ · E2E/
├── docker/                  ← Dockerfile, compose, konfigurasi nginx
├── .github/workflows/       ← pipeline CI/CD
└── ops/                     ← runbook, skrip cadangan, dasbor pemantauan
```

---

## 10. Utang Teknis yang Diterima Secara Sadar

Dicatat agar tidak menjadi kejutan di masa depan:

| # | Keputusan | Konsekuensi | Kapan ditinjau |
|---|-----------|-------------|----------------|
| 1 | Pencarian nama memakai `LIKE` + `FULLTEXT` MySQL | **Tidak toleran salah ketik**; relevansi rendah | Bila > 50 ribu penduduk, atau bila petugas sering mengeluh tidak menemukan warga |
| 2 | Laporan dibaca dari basis data utama | Laporan berat dapat memperlambat transaksi | Saat *read replica* dipasang |
| 3 | Tanpa TTE tersertifikasi di MVP | Keabsahan hukum surat bergantung tanda tangan basah/gambar | Fase 3 |
| 4 | Tanpa integrasi langsung Dukcapil | Data bergantung pemutakhiran manual | Setelah PKS tersedia |
| 5 | Formulir dinamis di kolom JSON | Pelaporan atas *field* dinamis lebih rumit | Bila kebutuhan laporan melampaui tabel ringkasan |
| 6 | Tanpa `tenant_id` sejak awal | Retrofit multi-desa 16–19 hari-orang (05 §10) | Bila jumlah desa diperkirakan > 10 |
| 7 | Isolasi wilayah/keluarga hanya di kode (tanpa RLS) | Bug otorisasi tidak tertangkap basis data | Terus-menerus — lewat uji otorisasi wajib di CI |
