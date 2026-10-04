# 14 — Laporan Sprint 1: Fondasi
**Periode:** Sprint 1 · **Status:** Selesai · **Tanggal:** 2026-10-05

Sasaran sprint (docs/11-roadmap-estimasi.md §2): autentikasi + 2FA, RBAC,
lapis repository + aturan analisis statis, identitas desa & wilayah,
design token & pustaka komponen dasar, audit log, CI/CD.

---

## 1. Hasil Kerja

### 1.1 Fondasi data (MySQL 8, single-tenant)
10 migrasi, 26 tabel. Tiga aturan bisnis **ditegakkan basis data**, bukan hanya kode:

| Aturan | Mekanisme | Terverifikasi |
|--------|-----------|---------------|
| RULE-01 NIK unik di antara penduduk aktif | Kolom *generated* `nik_hash_active` + UNIQUE | ✓ ditolak DB |
| RULE-02 Tepat satu kepala keluarga per KK | Kolom *generated* `family_head_key` + UNIQUE | ✓ ditolak DB |
| KEP-001 Hanya satu desa per instalasi | `CHECK (id = 1)` pada `village_profile` | ✓ ditolak DB |

### 1.2 Lapis repository — pengaman pengganti RLS
`GuardedRepository` + `ResidentRepository` + `FamilyRepository` + `AccessScope`.
Tidak ada jalan memperoleh *query builder* tanpa lebih dulu menyerahkan lingkup akses.

### 1.3 Autentikasi & 2FA
Login email/ponsel, anti-enumerasi, jeda bertingkat + penguncian akun, TOTP wajib
untuk Admin Desa & Kepala Desa, kode pemulihan sekali pakai, reset kata sandi,
kebijakan masa sesi (diam 30 menit / absolut 12 jam).

### 1.4 RBAC
**51 izin granular**, **11 peran** — terjemahan langsung matriks docs/03-srs.md §4.9.

| Peran | Jumlah izin | | Peran | Jumlah izin |
|---|---|---|---|---|
| Admin Desa | 51 | | Kasi Kesejahteraan | 14 |
| Kepala Desa | 20 | | Kasi Pelayanan | 13 |
| Kasi Pemerintahan | 18 | | Kaur Keuangan | 11 |
| Pengawas | 18 | | Kepala Dusun | 9 |
| Operator | 16 | | Kaur Perencanaan | 8 |
| | | | Warga | 5 |

### 1.5 Audit log
*Append-only* berlapis dua (model menolak + `REVOKE` MySQL), *hash chain*,
penyensoran data pribadi otomatis, perintah verifikasi keutuhan.

### 1.6 Enkripsi data pribadi (ADR-004)
NIK & nomor KK: AES-256-GCM di lapis aplikasi + HMAC untuk pencarian + masking.

### 1.7 Design token & komponen
`public/css/sid.css` (10 KB): token warna/tipografi/spasi dari docs/07 §3,
komponen dasar, 7 halaman autentikasi, 2 layout.

### 1.8 Kualitas & CI
3 *workflow* GitHub Actions. Dua tahap ditandai **WAJIB** dan memblokir rilis.

---

## 2. Status Pengujian

```
Architecture    3 uji     batas lapis repository
Authorization  15 uji     matriks wilayah & keluarga  ← lapis WAJIB
Unit           36 uji     enkripsi, NIK, normalisasi, penyensoran, aturan validasi
Feature        33 uji     login, 2FA, audit log, izin, header keamanan
─────────────────────────────────────────────────────
TOTAL          87 uji · 184 asersi · semua lolos
PHPStan level 6 · 0 error     Pint · bersih
```

### Verifikasi balik (uji yang membuktikan dirinya berguna)
Penjaga yang tidak pernah bisa gagal tidak bernilai. Keduanya diuji dengan
sengaja merusak kode:

| Yang dirusak | Hasil |
|--------------|-------|
| Kueri `Resident::query()` ditambahkan ke sebuah Controller | `RepositoryBoundaryTest` **gagal** dan menyebut berkasnya |
| Pembatasan wilayah di `ResidentRepository` dilumpuhkan | **5 dari 15** uji otorisasi gagal dengan pesan "KEBOCORAN DATA" |

### Verifikasi di peramban
Login Kepala Dusun → dashboard menampilkan "Dibatasi pada wilayah yang ditugaskan",
9 izin, menu audit log tidak muncul; akses langsung ke URL audit log → **403** dengan
pesan berarah. Login Admin Desa → dipaksa ke halaman pemasangan 2FA.

---

## 3. Temuan yang Mengoreksi Dokumen Perancangan

Lima hal di dokumen 04/05 ternyata **tidak dapat dijalankan** seperti tertulis.
Semuanya sudah diperbaiki di kode **dan** di dokumen.

| # | Temuan | Dampak & perbaikan |
|---|--------|--------------------|
| 1 | `CHECK (birth_date <= CURRENT_DATE)` **tidak sah** — MySQL *dan* PostgreSQL melarang fungsi non-deterministik di CHECK | Validasi dipindah ke `App\Rules\BirthDateRule` (FR-PDK-02). Dokumen 05 §3.2 dikoreksi |
| 2 | FK `residents.family_id` **tidak boleh** `ON DELETE SET NULL` — MySQL melarangnya pada kolom yang menjadi basis kolom *generated* | Diubah ke `RESTRICT`; konsisten dengan RULE-08. Dokumen 05 dikoreksi |
| 3 | `SELECT … FOR UPDATE` pada baris penghitung yang **belum ada** tidak mengunci apa pun | ADR-001 diperbarui: wajib `INSERT … ON DUPLICATE KEY` lebih dulu. **Kritis untuk Sprint 4** |
| 4 | Kolom `two_factor_secret VARBINARY(255)` terlalu kecil untuk *envelope* enkripsi Laravel (± 250 byte) | Dinaikkan ke 1024 / 4096 |
| 5 | Menyimpan model Eloquent ke cache Redis rapuh → `__PHP_Incomplete_Class` di seluruh halaman | Yang di-cache kini array atribut, bukan objek |

> Temuan 1, 2, dan 5 **hanya muncul saat kode dijalankan**, bukan saat ditinjau.
> Inilah alasan Sprint 1 menyertakan verifikasi di peramban, bukan hanya uji otomatis.

---

## 4. Penyimpangan dari Rencana (disengaja)

| Rencana | Yang dikerjakan | Alasan |
|---------|-----------------|--------|
| Laravel 11 | **Laravel 13.34** | Versi stabil terbaru; PHP 8.5 di mesin pengembang bermasalah dengan Laravel 11 |
| Panel admin Inertia + React | **Blade + CSS token** | Sprint 1 membutuhkan *token*-nya, bukan panelnya. Inertia/React dipasang Sprint 2 bersama modul kependudukan, dan akan mengonsumsi token yang sama |
| Modul kependudukan di Sprint 2 | **Tabel + repository sudah di Sprint 1** | `users.resident_id` memerlukan tabelnya; dan risiko R-15 (bug otorisasi) perlu divalidasi sedini mungkin. CRUD/UI tetap Sprint 2 |
| Docker Compose lokal | **Layanan native (brew)** | Docker tidak tersedia di mesin ini. CI tetap memakai kontainer; `docker-compose.prod.yml` dibuat di Sprint 7 |

---

## 5. Risiko: Perubahan Status

| Risiko | Sebelum | Sesudah | Alasan |
|--------|---------|---------|--------|
| **R-15** Bug otorisasi lolos karena tiadanya RLS | 15 (tertinggi) | **8** | Tiga lapis mitigasi terpasang & **terbukti menangkap kebocoran nyata** |
| **R-13** Nomor surat ganda/terlewat | 10 | **10** (tetap) | Belum dikerjakan (Sprint 4), tetapi jebakan MySQL-nya sudah ditemukan & didokumentasikan |

### Risiko baru
| ID | Risiko | P | D | Skor | Mitigasi |
|----|--------|---|---|------|----------|
| R-17 | Pengembang memakai MySQL > 8.0 lokal lalu produksi 8.0 gagal | 3 | 3 | 9 | CI dikunci `mysql:8.0`; tercatat di CONTRIBUTING.md |
| R-18 | `PII_ENCRYPTION_KEY` hilang → seluruh NIK tidak dapat dibaca, **tanpa cara pulih** | 2 | 5 | **10** | Peringatan eksplisit di perintah pembuat kunci; **prosedur pencadangan kunci terpisah wajib disepakati sebelum go-live** |

> **R-18 perlu keputusan Anda.** Kunci enkripsi harus dicadangkan **terpisah** dari
> cadangan basis data — menyimpannya bersama membuat enkripsi tidak ada gunanya.
> Tentukan siapa yang memegang cadangan kunci dan di mana, sebelum data warga asli masuk.

---

## 6. Belum Dikerjakan di Sprint 1

| Item | Alasan | Jadwal |
|------|--------|--------|
| Notifikasi email sungguhan (reset sandi) | `MAIL_MAILER=log`; tautan dicatat ke log | Sprint 6 |
| Pemindaian antivirus unggahan | Belum ada fitur unggah | Sprint 5 |
| `docker-compose.prod.yml` | Belum ada target *deploy* | Sprint 7 |
| Halaman verifikasi akun warga | Registrasi warga adalah lingkup Sprint 6 | Sprint 6 |
| Inertia + React + Tailwind | Lihat §4 | Sprint 2 |

---

## 7. Siap untuk Sprint 2

Sprint 2 (kependudukan: CRUD, pencarian, riwayat perubahan, masking) dapat langsung
dimulai. Yang sudah tersedia:

- Tabel `residents` & `families` beserta penegakan RULE-01/RULE-02
- `ResidentRepository` & `FamilyRepository` dengan pencarian ter-lingkup
- Enkripsi/masking NIK siap pakai (`setNik()`, `revealNik()`, `nik_masked`)
- `AuditLogger::logModelChange()` untuk riwayat perubahan (FR-PDK-07)
- 51 izin termasuk `penduduk.*` sudah ter-*seed*
- Penjaga arsitektur yang otomatis menolak kueri di luar repository

**Dua hal yang perlu diputuskan sebelum/awal Sprint 2:**
1. Prosedur pencadangan kunci PII (R-18) — siapa pemegang, disimpan di mana.
2. Konfirmasi Inertia + React untuk panel admin (alternatif: tetap Blade + Alpine,
   lebih sederhana dirawat desa, tetapi kurang nyaman untuk tabel data besar).
