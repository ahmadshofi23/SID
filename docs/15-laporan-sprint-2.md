# 15 — Laporan Sprint 2: Kependudukan Inti
**Periode:** Sprint 2 · **Status:** Selesai · **Tanggal:** 2026-10-05

Sasaran (docs/11 §2): CRUD penduduk & kartu keluarga, wilayah, tabel referensi,
pencarian, riwayat perubahan, masking data.

---

## 1. Hasil Kerja

| Kemampuan | Rujukan | Keterangan |
|-----------|---------|------------|
| CRUD penduduk | FR-PDK-01 | Tambah, lihat, ubah, hapus (soft delete), dengan validasi penuh |
| CRUD kartu keluarga | FR-PDK-08 | Termasuk daftar anggota & peringatan KK tanpa kepala keluarga |
| Pemindahan anggota antar-KK | FR-PDK-09 | Alasan wajib; tercatat lengkap di riwayat |
| Pengelolaan wilayah | FR-PDK-14 | Dusun → RW → RT, hierarki & `path` dijaga otomatis |
| Pencarian | FR-PDK-04 | Nama (sebagian), NIK & no. KK (persis), + saringan wilayah/JK/status |
| Autocomplete | FR-PDK-04 | Minimal 3 karakter; **tidak pernah** mengembalikan NIK penuh |
| Riwayat perubahan | FR-PDK-07, BR-02 | Per-field, nilai lama→baru, dibaca dari audit log |
| Masking & pembukaan NIK | RULE-07 | Ter-masking bawaan; pembukaan butuh izin khusus & selalu tercatat |
| Tabel referensi | FR-PDK-03 | 12 tabel; validasi formulir bersumber dari data, bukan kode |

### Lapis yang dibangun
```
Controller  → tidak pernah menyentuh tabel sensitif (ditegakkan tests/Architecture)
Service     → ResidentService · FamilyService · RegionService · ChangeHistoryService
Repository  → ResidentRepository · FamilyRepository  (pembatasan wilayah & keluarga)
Model       → penegakan RULE-01/RULE-02 di tingkat basis data
```

### Antarmuka
10 view Blade + 3 komponen baru (`select`, `sensitive`, `confirm-form`) + layout admin
dengan navigasi sadar-peran. CSS 19 KB, JavaScript 3 KB — tanpa langkah *build*.

---

## 2. Status Pengujian

```
Architecture     3 uji
Authorization   29 uji   (15 Sprint 1 + 14 baru lewat jalur HTTP)
Unit            36 uji
Feature         59 uji   (33 Sprint 1 + 26 baru)
──────────────────────────────────────────
TOTAL          127 uji · 288 asersi · semua lolos
PHPStan level 6 · 0 error      Pint · bersih
```

Uji otorisasi baru menutup celah yang tidak dijangkau Sprint 1: pembatasan wilayah
dan keluarga **melalui URL nyata** — halaman detail, daftar, pencarian nama,
pencarian NIK persis, autocomplete, dan pembukaan NIK.

### Verifikasi di aplikasi nyata
Login tiga peran berbeda pada data yang sama:

| Peran | Penduduk terlihat | Benar? |
|-------|-------------------|--------|
| Kepala Dusun Krajan | 4 (hanya Dusun Krajan) | ✓ |
| Operator | 7 (seluruh desa) | ✓ |
| Warga | 3 (hanya anggota KK-nya) | ✓ |

NIK tampil ter-masking di HTML; Operator menerima **403** saat mencoba membukanya.
Tampilan ponsel (375 px) berubah menjadi kartu tanpa gulir horizontal.

---

## 3. Temuan Penting

### 3.1 Kebocoran otorisasi laten — `AccessScope` ter-cache antar-permintaan ⚠

**Masalah:** `AccessScope` diikat dengan `$this->app->scoped()` pada Sprint 1.
Pada runtime yang menggunakan ulang container — **Laravel Octane**, dan pengujian
HTTP berurutan — objek itu bertahan melewati batas permintaan. Akibatnya
**pengguna berikutnya memakai lingkup akses pengguna sebelumnya**.

**Cara ditemukan:** uji `pembukaan_nik_terbatas_dan_tercatat` gagal dengan 403
untuk Admin Desa, karena permintaan sebelumnya dalam uji yang sama memakai
Operator — dan lingkupnya tidak diperbarui.

**Dampak bila lolos:** hanya muncul pada permintaan **kedua dan seterusnya** dalam
satu proses. Di produksi tanpa Octane tidak terpicu, sehingga bug ini bisa tidur
berbulan-bulan dan baru aktif saat Octane dipasang untuk alasan kinerja.

**Perbaikan:** ikatan diubah menjadi transient (`bind`), dengan komentar yang
menjelaskan mengapa caching di sini berbahaya. Biayanya mendekati nol.

### 3.2 Riwayat perubahan mencatat "lama = baru"

`getOriginal()` setelah `save()` sudah tersinkron ke nilai baru (Eloquent memanggil
`syncOriginal()` di dalam `finishSave()`), sehingga seluruh riwayat perubahan akan
mencatat nilai lama yang salah — fitur yang tampak jalan tetapi tidak berguna.

**Perbaikan:** `logModelChange()` kini **mewajibkan** parameter `$before` yang
ditangkap pemanggil sebelum `save()`. Dibuat wajib, bukan opsional, justru agar
tidak mungkin terlupakan di modul berikutnya.

### 3.3 Pelanggaran lazy loading pada `displayPath()`

`displayPath()` menelusuri rantai induk sampai `null`, yang berarti mengakses
`->parent` pada dusun (yang tidak punya induk dan tidak pernah di-*eager load*).
Dengan `preventLazyLoading` aktif, ini melempar galat — **HTTP 500 di halaman
daftar penduduk**.

**Lolos dari 127 uji** karena `TestCase` mematikan `preventLazyLoading`.

> ### ⚠ KOREKSI 2026-10-08
>
> Laporan ini semula menyatakan pengujian "kini menyalakan larangan tersebut".
> **Itu tidak benar.** Penggantian teks pada `tests/TestCase.php` tidak cocok dengan
> isi berkas dan gagal diam-diam; saya melaporkannya berhasil tanpa memverifikasi.
>
> Akibatnya nyata: halaman **Wilayah** rusak dengan HTTP 500 di hadapan pengguna
> (`tree()` memuat dua tingkat sementara penyaji menelusuri tiga), dan uji yang
> seharusnya menangkapnya justru lolos.
>
> Diperbaiki pada 2026-10-08 — rinci di [16 Perbaikan Pasca-Sprint 2](16-perbaikan-pasca-sprint-2.md).
> Larangan lazy loading kini benar-benar aktif saat pengujian, dan langsung
> memunculkan dua pelanggaran yang selama ini tersembunyi.

### 3.4 Penjaga arsitektur menangkap pelanggaran baru

`RegionService` sempat memakai `DB::table('residents')` untuk memeriksa apakah
wilayah masih dihuni. Penjaga menolaknya; kuerinya dipindahkan ke
`ResidentRepository::countInRegionIgnoringScope()` — yang hanya mengembalikan
**angka**, tidak pernah satu baris data pun.

### 3.5 Keputusan kecil dengan alasan keamanan

| Keputusan | Alasan |
|-----------|--------|
| Pesan "NIK sudah terdaftar" **tidak menyebut nama** pemiliknya | Kepala Dusun bisa memakainya untuk mengintip identitas warga dusun lain — melewati pembatasan wilayah lewat pintu belakang |
| Pesan "KK sudah punya kepala keluarga" **menyebut nama** | Keduanya dalam satu KK yang sama, jadi petugas memang berhak melihatnya |
| NIK **tidak di-*prefill*** pada formulir ubah; kosong = tidak diubah | Mem-*prefill* akan menaruh NIK asli di HTML bagi semua yang boleh mengubah data |
| Pemeriksaan keunikan NIK **mengabaikan lingkup akses** | Keunikan berlaku se-desa. Bila ter-lingkup, Kepala Dusun bisa membuat NIK ganda tanpa menyadarinya |

---

## 4. Keputusan Arsitektur: KEP-005

Panel admin dibangun dengan **Blade + CSS token**, bukan Inertia + React seperti
rencana awal (ADR-006). Alasan utama: sistem harus dapat dirawat perangkat desa atau
Diskominfo kabupaten setelah serah terima, dan satu kerangka kerja jauh lebih mungkin
dirawat daripada PHP + Node + React + proses *build*.

**Reversibel dengan biaya rendah** (± 15–20 hari-orang) karena seluruh aturan bisnis
berada di lapis service/repository yang tidak mengetahui apa pun tentang UI.
Rincian & yang dikorbankan: [00 Log Keputusan](00-log-keputusan.md) KEP-005.

> Bila Anda menginginkan Inertia + React, penggantian paling murah dilakukan
> **sekarang**, selagi baru satu modul yang dibangun.

---

## 5. Belum Dikerjakan (sesuai rencana)

| Item | Jadwal |
|------|--------|
| Impor massal Excel + pra-validasi (FR-PDK-11) | Sprint 3 |
| Mutasi penduduk: lahir, mati, pindah (FR-PDK-10) | Sprint 3 |
| Laporan anomali data (FR-PDK-13) | Sprint 3 |
| Statistik agregat & tabel `population_stats` (FR-PDK-15) | Sprint 3 |
| Usulan koreksi data oleh Kepala Dusun (FR-PDK-16) | Sprint 3 |
| Ekspor data penduduk (FR-PDK-12) | Sprint 3 |
| Pemulihan penduduk terhapus (UI) — layanannya sudah ada | Sprint 3 |

---

## 6. Risiko: Perubahan Status

| Risiko | Sebelum | Sesudah | Alasan |
|--------|---------|---------|--------|
| **R-15** Bug otorisasi lolos karena tiadanya RLS | 8 | **6** | Lapis uji HTTP ditambahkan; satu bug laten (§3.1) ditemukan & ditutup sebelum produksi |
| **R-18** Kehilangan kunci PII | 10 | **4** | **Ditutup (KEP-006):** dua pemegang, dicetak di kertas; prosedur + alat verifikasi tersedia |

**R-18 ditutup pada 2026-10-08** lewat KEP-006: kunci dicadangkan di kertas, dipegang
dua orang di dua lokasi berbeda. Prosedur lengkap beserta formulir cetak ada di
[ops/runbook/pencadangan-kunci.md](../ops/runbook/pencadangan-kunci.md), dan
verifikasi salinan dibantu perintah `php artisan sid:pii-key --verify`.

Risiko sisa (skor 4) adalah hal yang tidak dapat dihilangkan teknologi: kertas bisa
terbakar atau hilang. Itu sebabnya dua salinan di dua lokasi.

**Yang masih harus dilakukan manusia:** menjalankan prosedur itu sebelum data warga
asli yang pertama dimasukkan.

### Risiko baru
| ID | Risiko | P | D | Skor | Mitigasi |
|----|--------|---|---|------|----------|
| R-19 | Pencarian nama tidak toleran salah ketik (konsekuensi KEP-002) membuat petugas mengira warga belum terdaftar, lalu membuat data ganda | 3 | 3 | 9 | Pencarian menerima NIK/no. KK persis; hasil menampilkan nama + umur + wilayah. **Laporan anomali duplikat (Sprint 3) menjadi jaring pengaman utama** |

---

## 7. Siap untuk Sprint 3

Tersedia untuk langsung dipakai:
- `ResidentService` & `FamilyService` dengan seluruh aturan bisnis — impor massal
  tinggal memanggilnya per baris, sehingga aturan yang sama berlaku untuk entri
  manual maupun impor.
- `ReferenceData` untuk memetakan kode saat impor.
- `AuditLogger::logModelChange()` dengan penangkapan keadaan sebelum perubahan.
- Penjaga arsitektur & uji otorisasi yang otomatis menguji modul baru.

**Perlu diputuskan sebelum Sprint 3:**
1. ~~Prosedur pencadangan kunci PII~~ **Terjawab (KEP-006).** Tinggal dijalankan.
2. Format berkas impor: template Excel resmi kami, atau mengikuti format ekspor
   sistem yang sudah dipakai desa (OpenSID/Prodeskel)? Menentukan pemetaan kolom.
