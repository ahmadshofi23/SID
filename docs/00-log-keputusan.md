# 00 — Log Keputusan Proyek (Decision Log)

Catatan keputusan yang sudah **final** beserta alasan dan konsekuensinya. Dokumen lain
mengikuti keputusan di sini. Perubahan keputusan wajib lewat Change Request (01 §9).

---

## KEP-001 — Lingkup: **satu desa** (single-tenant)
**Tanggal:** 2026-10-04 · **Status:** Disetujui · **Pemutus:** Pemilik proyek

**Keputusan:** Sistem dibangun untuk **satu desa**. Tidak ada multi-tenancy.

**Konsekuensi positif:**
- Skema basis data lebih sederhana: **tidak ada kolom `tenant_id`** di mana pun.
- Tidak perlu Row-Level Security, *global scope* tenant, atau middleware tenant.
- Risiko kebocoran lintas desa — kelas bug paling berbahaya pada sistem multi-tenant —
  **hilang sepenuhnya**.
- Lapis uji otorisasi menyusut dari (peran × endpoint × 6 skenario kepemilikan) menjadi
  (peran × endpoint × 4 skenario). Hemat ±1 sprint.
- Volume data kecil (± 5.000 penduduk) → banyak kekhawatiran kinerja tidak berlaku.

**Konsekuensi negatif / yang dikorbankan:**
- Bila kelak ingin melayani beberapa desa dalam satu instalasi, perlu **retrofit**:
  tambah `tenant_id` ke ±18 tabel, tambahkan ke indeks komposit, tambahkan *global
  scope*, dan **uji ulang seluruh otorisasi**. Perkiraan 15–25 hari-orang.
- Mitigasi risiko retrofit: semua akses data wajib lewat satu lapis *repository/service*
  (04 §3.3), sehingga filter tenant kelak hanya ditambahkan di satu tempat, bukan
  tersebar di kontroler. **Ini satu-satunya syarat arsitektural yang wajib dijaga
  sejak Sprint 1.**

**Jalur alternatif bila multi-desa ternyata dibutuhkan ≤ 12 bulan:** pasang instalasi
terpisah per desa (database + container sendiri). Untuk < 10 desa ini sering **lebih
murah dan lebih aman** daripada retrofit multi-tenancy.

> Bila sejak sekarang sudah pasti akan ada lebih dari satu desa, beri tahu — lebih baik
> `tenant_id` ditambahkan di Sprint 1 daripada di-retrofit nanti.

---

## KEP-002 — Basis data: **MySQL 8.0**
**Tanggal:** 2026-10-04 · **Status:** Disetujui · **Pemutus:** Pemilik proyek

**Keputusan:** MySQL 8.0 (InnoDB, `utf8mb4_0900_ai_ci`), menggantikan rekomendasi
awal PostgreSQL.

**Mengapa ini sekarang pilihan yang wajar:** keberatan utama terhadap MySQL pada
dokumen 04 adalah **tidak adanya Row-Level Security** untuk isolasi antar-desa.
Dengan KEP-001, kebutuhan itu tidak ada lagi. Sisa perbedaan bersifat teknis dan
seluruhnya punya padanan di MySQL 8.

**Padanan fitur (rinci di 05 §9):**
| Kebutuhan | PostgreSQL | MySQL 8 — padanan yang dipakai |
|-----------|-----------|-------------------------------|
| NIK unik hanya untuk penduduk aktif | *partial unique index* | Kolom *generated* `nik_hash_active` + UNIQUE |
| Satu kepala keluarga per KK | *partial unique index* | Kolom *generated* `family_head_key` + UNIQUE |
| Nomor surat tanpa duplikat & tanpa celah | `SELECT … FOR UPDATE` | **Sama** — InnoDB mendukung `FOR UPDATE` |
| Formulir dinamis | JSONB + GIN | `JSON` + *generated column* + indeks |
| Statistik agregat | *materialized view* | Tabel ringkasan `population_stats` + *job* penyegar |
| Pencarian nama toleran salah ketik | `pg_trgm` | Kolom nama ternormalisasi + `LIKE` (memadai di 5.000 baris) |
| Audit log tak dapat diubah | `REVOKE` + partisi | **`REVOKE` sama**; partisi ditunda (volume kecil) |
| Enkripsi kolom NIK | di lapis aplikasi | **Sama** (di lapis aplikasi, bukan fitur DB) |

**Konsekuensi negatif yang diterima:**
1. **Pencarian nama tidak toleran salah ketik.** "Sitia" tidak akan menemukan "Siti".
   Di 5.000 baris `LIKE '%siti%'` cepat, tetapi tetap pencocokan literal.
   *Mitigasi:* pencarian juga menerima NIK & nomor KK persis; UI menampilkan nama +
   umur + dusun agar petugas yakin memilih orang yang benar.
2. **Isolasi data sepenuhnya bergantung kode aplikasi.** Tidak ada jaring pengaman
   tingkat basis data untuk pembatasan wilayah (Kadus) dan data-sendiri (Warga).
   *Mitigasi:* lapis uji otorisasi (09 §4.3) menjadi **wajib mutlak**, bukan opsional.
3. **Statistik dapat tertinggal** sampai *job* penyegar berjalan (interval 1 jam,
   plus penyegaran langsung setelah impor massal & mutasi).

**Yang tidak berubah:** seluruh aturan bisnis, API, UI/UX, keamanan aplikasi, dan
strategi pengujian. Pergantian basis data **tidak** menyentuh dokumen 02, 03, 06, 07,
08 (kecuali satu bagian), 09, 11.

---

## KEP-003 — Jenis surat: **katalog umum, 8 prioritas + 12 lanjutan**
**Tanggal:** 2026-10-04 · **Status:** Disetujui · **Pemutus:** Pemilik proyek

**Keputusan:** Sistem dikirim dengan katalog jenis surat **umum** yang sudah siap pakai
(dokumen 13), bukan menunggu daftar khusus dari satu desa.

**Konsekuensi:**
- Sprint 4–5 **tidak lagi terblokir** oleh ketersediaan daftar surat dari desa.
- Katalog diisi lewat *seeder* saat pemasangan; petugas dapat mengubah nama, syarat,
  isi template, dan pola nomor **tanpa bantuan programmer** (FR-SRT-01…03).
- **Yang tetap wajib diverifikasi sebelum go-live:** pola penomoran surat dan nama
  pejabat penanda tangan — dua hal yang berbeda di setiap desa dan tidak dapat
  diasumsikan. Selebihnya cukup disesuaikan lewat menu pengaturan.

**Risiko yang tersisa:** format/tata letak surat tiap desa bisa berbeda dari template
bawaan. Karena template berupa HTML yang dapat disunting (ADR-003), penyesuaian ini
adalah pekerjaan konfigurasi (± 0,5 hari-orang/template), bukan pengembangan.

---

## KEP-004 — Stack aplikasi (menyesuaikan KEP-002)
**Tanggal:** 2026-10-04 · **Status:** Disetujui

| Lapis | Pilihan |
|-------|---------|
| Backend | PHP 8.3+ + **Laravel 13** (naik dari rencana Laravel 11 — lihat 14 §4) |
| Basis data | **MySQL 8.0** (KEP-002) |
| Panel admin | Inertia.js + React + TypeScript + Tailwind (mulai Sprint 2) |
| Portal publik | Blade (SSR) |
| Cache / queue / lock | Redis 7 |
| PDF | Gotenberg (Chromium *headless*) |
| Berkas | S3-kompatibel (MinIO atau object storage penyedia) |

Alasan Laravel + MySQL: kombinasi yang paling mudah di-*hosting* di Indonesia, paling
banyak SDM-nya untuk pemeliharaan jangka panjang, dan tidak mengunci desa pada satu
penyedia.

---

## KEP-005 — Panel admin: **Blade + Alpine.js**, bukan Inertia/React
**Tanggal:** 2026-10-05 · **Status:** Disetujui (diambil Tech Lead, dapat ditinjau ulang)
**Mengubah:** ADR-006 (docs/04 §5) dan docs/07 §3 yang semula menetapkan Inertia + React

**Keputusan:** panel administrasi dibangun dengan **Blade (SSR) + Alpine.js**,
memakai design token CSS dari Sprint 1. Tidak ada Inertia, React, atau Node pada
jalur produksi.

**Alasan:**
1. **Serah terima (KEP-001, docs/01 §6).** Sistem ini harus dapat dirawat perangkat
   desa atau Diskominfo kabupaten setelah serah terima. Satu bahasa (PHP) dan satu
   kerangka kerja jauh lebih mungkin dirawat daripada PHP + Node + React + proses *build*.
2. **Skala tidak menuntutnya.** 5.000 penduduk dan segelintir operator (docs/05 §8).
   Keunggulan SPA — interaksi tanpa muat ulang pada data besar — tidak terasa di sini,
   sementara biayanya (pipeline build, dua skill set, *bundle* 300 KB) tetap penuh.
3. **Jaringan desa.** Blade SSR memberi halaman utuh dalam satu permintaan; tidak ada
   *bundle* JavaScript yang harus diunduh sebelum layar pertama tampil (NFR-PERF-04).
4. **Sprint 1 sudah menyediakan fondasinya.** Design token dan komponen dasar sudah
   ada dan langsung terpakai, tanpa dikerjakan ulang dalam React.

**Yang dikorbankan:**
- Interaksi kaya (tabel dengan *inline editing*, *drag-drop*) lebih sulit. Untuk
  kebutuhan Sprint 2–5 hal itu tidak diperlukan.
- Pencarian *autocomplete* dan filter memerlukan sedikit JavaScript manual
  (Alpine, ± 15 KB) alih-alih komponen siap pakai.

**Mengapa ini reversibel dengan biaya rendah:** seluruh aturan bisnis berada di lapis
*service* dan *repository* yang tidak mengetahui apa pun tentang UI. Mengganti view
Blade dengan Inertia/React kelak berarti menulis ulang lapis presentasi saja
(perkiraan 15–20 hari-orang), **tanpa** menyentuh logika, otorisasi, atau pengujian.

> Bila Anda tetap menginginkan Inertia + React, beri tahu — penggantian paling murah
> dilakukan sekarang, selagi baru satu modul yang dibangun.

---

## KEP-006 — Pencadangan kunci data pribadi: **dua pemegang, dicetak di kertas**
**Tanggal:** 2026-10-08 · **Status:** Disetujui · **Pemutus:** Pemilik proyek
**Menutup risiko:** R-18 (docs/14 §5, docs/15 §6)

**Keputusan:** `PII_ENCRYPTION_KEY` dan `PII_HASH_PEPPER` dicadangkan dengan cara
**dicetak/ditulis di kertas**, dipegang **dua orang** secara terpisah.

**Mengapa kertas justru pilihan yang baik di sini — bukan kompromi:**
- Tidak dapat diretas dari jarak jauh. Ancaman terbesar terhadap kunci ini adalah
  penyerang yang sudah masuk ke server atau ke penyimpanan cadangan; kertas di
  brankas berada sepenuhnya di luar jangkauan itu.
- Tidak bergantung pada langganan, listrik, atau perangkat yang bisa rusak.
- Dapat dibaca siapa pun 10 tahun lagi tanpa perangkat lunak khusus.

**Yang dikorbankan:** kertas bisa terbakar, basah, hilang, atau salah dibaca
(angka 0 vs huruf O). Karena itu dua pemegang, dan karena itu disediakan perintah
verifikasi di §Prosedur agar kesalahan penyalinan ketahuan **saat itu juga**,
bukan saat kunci dibutuhkan dalam keadaan darurat.

**Aturan mutlak yang menyertainya:**
1. Kertas **TIDAK BOLEH** disimpan di tempat yang sama dengan cadangan basis data.
   Menyimpannya bersama membuat seluruh enkripsi tidak ada gunanya — seperti
   menempelkan kunci rumah di pintu depan.
2. Kunci **TIDAK BOLEH** dikirim lewat WhatsApp, email, atau difoto. Riwayat
   percakapan dan galeri ponsel adalah tempat kebocoran yang paling umum.
3. Dua pemegang menyimpan di **dua lokasi fisik berbeda** (mis. brankas kantor desa
   dan brankas/tempat terkunci di rumah pemegang kedua). Satu kebakaran tidak boleh
   menghabiskan keduanya.

**Prosedur lengkap:** [ops/runbook/pencadangan-kunci.md](../ops/runbook/pencadangan-kunci.md)

**Kapan wajib dilakukan:** sebelum baris data warga **asli** yang pertama ditulis —
bukan sebelum go-live. Selama isinya masih data contoh, kunci hilang tidak berakibat
apa-apa; tinggal dibuat ulang.
