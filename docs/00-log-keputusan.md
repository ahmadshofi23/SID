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
