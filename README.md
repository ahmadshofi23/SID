# Sistem Informasi Desa (SID)

**Status:** Sprint 2 (Kependudukan) **selesai** — lihat [laporan Sprint 2](docs/15-laporan-sprint-2.md).

```bash
make setup && make serve     # http://localhost:8000
make check                   # pint + phpstan + 132 uji (sama dengan CI)
```

Panduan pengembangan: [CONTRIBUTING.md](CONTRIBUTING.md)

---

## Paket Dokumen Perancangan

Dokumen perancangan lengkap untuk pengembangan Sistem Informasi Desa berbasis web.
Disusun mengikuti alur kerja standar *Business Analyst → Software Architect → Engineering*,
siap dipakai sebagai dasar kontrak kerja, estimasi, dan implementasi.

## Keputusan yang sudah final

| # | Keputusan | Hasil |
|---|-----------|-------|
| KEP-001 | **Lingkup** | **Satu desa** (single-tenant) — tanpa `tenant_id`, tanpa multi-tenancy |
| KEP-002 | **Basis data** | **MySQL 8.0** (InnoDB, utf8mb4) |
| KEP-003 | **Jenis surat** | **Katalog umum 22 jenis** — 8 aktif saat pemasangan, 14 siap diaktifkan |
| KEP-004 | **Stack** | **Laravel 13** + MySQL 8 + Redis + Gotenberg |
| KEP-005 | **Panel admin** | **Blade + CSS token** (bukan Inertia/React) — reversibel, lihat KEP-005 |
| KEP-006 | **Cadangan kunci data pribadi** | Dicetak di kertas, dua pemegang, dua lokasi — [prosedurnya](ops/runbook/pencadangan-kunci.md) |

Alasan, konsekuensi, dan hal yang dikorbankan dari setiap keputusan: **[00 Log Keputusan](docs/00-log-keputusan.md)**.

## Daftar dokumen

| No | Dokumen | Peruntukan | Versi |
|----|---------|-----------|-------|
| 00 | [Log Keputusan](docs/00-log-keputusan.md) | Semua — baca pertama | 1.0 |
| 01 | [Project Charter](docs/01-project-charter.md) | Sponsor, Kades, PM | 2.0 |
| 02 | [Business Requirements Document (BRD)](docs/02-brd.md) | Stakeholder, BA | 2.0 |
| 03 | [Software Requirements Specification (SRS)](docs/03-srs.md) | BA, Dev, QA | 2.0 |
| 04 | [Arsitektur Sistem](docs/04-arsitektur.md) | Architect, Dev, DevOps | 2.0 |
| 05 | [Model Data & ERD (MySQL)](docs/05-model-data.md) | Dev, DBA | 2.0 |
| 06 | [Spesifikasi API](docs/06-api-spec.md) | Dev FE/BE, integrator | 2.0 |
| 07 | [UI/UX & Information Architecture](docs/07-ui-ux.md) | Designer, Dev FE | 1.0 |
| 08 | [Keamanan & Pelindungan Data Pribadi](docs/08-keamanan-privasi.md) | Security, Legal | 2.0 |
| 09 | [Strategi Pengujian (QA Plan)](docs/09-testing-qa.md) | QA, Dev | 2.0 |
| 10 | [Deployment & DevOps](docs/10-deployment-devops.md) | DevOps, SysAdmin | 2.0 |
| 11 | [Roadmap, Estimasi & Risiko](docs/11-roadmap-estimasi.md) | PM, Sponsor | 2.0 |
| 12 | [Glosarium](docs/12-glosarium.md) | Semua | 1.0 |
| 13 | [Katalog Jenis Surat & Template](docs/13-katalog-surat.md) | BA, Dev, Sekdes | 1.0 |
| 14 | [Laporan Sprint 1](docs/14-laporan-sprint-1.md) | PM, Dev, Sponsor | 1.0 |
| 15 | [Laporan Sprint 2](docs/15-laporan-sprint-2.md) | PM, Dev, Sponsor | 1.0 |

## Cara membaca
- **Non-teknis (Kades, Sekdes, sponsor):** 00, 01, 02, 11, 13
- **Developer:** 00, 03, 04, 05, 06, 07, 13
- **Operasional & keamanan:** 08, 09, 10

## Ringkasan teknis

| Aspek | Nilai |
|-------|-------|
| Skala sasaran | ± 5.000 penduduk, 1.500 KK, ± 50 surat/hari |
| Ukuran basis data (5 tahun) | < 1 GB — lihat [05 §8](docs/05-model-data.md) |
| Modul MVP | Kependudukan · Persuratan · Portal publik · Pengaduan · RBAC · Laporan |
| Sudah jadi | Fondasi (S1) · Kependudukan: penduduk, KK, wilayah (S2) |
| Estimasi MVP | 666 SP ≈ 340 hari-orang ≈ 17 minggu (tim 4 orang) |
| Biaya operasional | ± Rp 400–600 rb/bulan |
| Jenis surat siap pakai | 8 aktif + 14 tersedia ([13 §7](docs/13-katalog-surat.md)) |

## Yang masih perlu diputuskan

Tidak memblokir pengembangan sekarang, tetapi dibutuhkan sebelum sprint terkait
(rinci di [11 §8](docs/11-roadmap-estimasi.md)):

1. **Jalankan prosedur cadangan kunci** ⚠ — [ops/runbook/pencadangan-kunci.md](ops/runbook/pencadangan-kunci.md)
   → **sebelum data warga asli yang pertama dimasukkan**, bukan sebelum go-live.
2. **Model hosting** — VPS penyedia atau on-premise kabupaten.
3. **Kondisi data awal** — digital, hardcopy, atau belum ada → sebelum Sprint 3.
4. **Pola penomoran surat** ⚠ — ambil dari arsip surat asli desa → sebelum surat pertama
   terbit. Paling mudah terlewat, paling mahal diperbaiki.
5. **Pejabat penanda tangan** per jenis surat → sebelum Sprint 5.

## Catatan penting

1. **Rujukan regulasi** pada dokumen ini adalah ringkasan kerja, **bukan nasihat hukum**.
   Verifikasi nomor & pasal terbaru dengan DPMD/Bagian Hukum kabupaten sebelum mengunci
   proses bisnis persuratan dan keuangan.
2. **Risiko utama akibat KEP-002:** MySQL tidak punya Row-Level Security, sehingga
   pembatasan akses per wilayah (Kepala Dusun) dan per keluarga (Warga) **sepenuhnya
   bergantung pada kode**. Dua pengaman wajib: lapis *repository* tunggal
   ([04 §4.1](docs/04-arsitektur.md)) dan uji otorisasi penuh yang memblokir rilis
   ([09 §4.3](docs/09-testing-qa.md)). Keduanya tidak boleh dipangkas.
3. **Asumsi yang masih perlu dikonfirmasi** ditandai blok `> **ASUMSI:**` di dalam dokumen.
4. *Living document.* Perubahan lingkup wajib melalui Change Request
   ([01 §9](docs/01-project-charter.md)) dan menaikkan versi dokumen terkait.

---
Versi paket: **2.0** · Tanggal: **2026-10-04**
