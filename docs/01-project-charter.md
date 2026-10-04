# 01 — Project Charter
**Proyek:** Sistem Informasi Desa (SID) Berbasis Web
**Versi:** 2.0 · **Tanggal:** 2026-10-04 · **Status:** Draft untuk persetujuan
**Lingkup:** satu desa · **Basis data:** MySQL 8 · lihat [00 Log Keputusan](00-log-keputusan.md)

---

## 1. Latar Belakang

Pemerintah desa mengelola data penduduk, pelayanan administrasi, keuangan, aset, dan
pembangunan — sebagian besar masih berbasis buku administrasi manual dan berkas Excel
yang terpisah. Akibatnya:

- **Data tidak tunggal.** Jumlah penduduk di buku induk, laporan bulanan, dan data
  bantuan sosial sering berbeda.
- **Layanan lambat.** Warga harus datang ke kantor desa 2–3 kali untuk satu surat
  keterangan; antre saat jam kerja, sementara banyak warga bekerja di luar desa.
- **Transparansi rendah.** APBDes hanya tertempel di papan informasi; tidak dapat
  diakses warga yang merantau.
- **Risiko kehilangan data & pengetahuan.** Pergantian perangkat desa membuat data
  dan riwayat layanan hilang karena tersimpan di laptop pribadi.
- **Beban pelaporan ganda.** Data yang sama diminta ulang oleh kecamatan, DPMD,
  Dinas Sosial dalam format berbeda.

## 2. Tujuan Proyek (Objectives)

| Kode | Tujuan | Indikator Keberhasilan (target 12 bulan pasca go-live) |
|------|--------|--------------------------------------------------------|
| O-1 | Membangun satu basis data kependudukan desa yang tunggal & terkini | ≥ 98% penduduk ter-input; selisih dengan data Dukcapil ≤ 2% |
| O-2 | Memangkas waktu layanan surat | Rata-rata dari 2 hari kerja → **≤ 1 jam** untuk 8 jenis surat prioritas (13 §7) |
| O-3 | Membuka kanal layanan mandiri 24/7 | ≥ 40% pengajuan surat masuk lewat kanal online |
| O-4 | Meningkatkan transparansi anggaran | APBDes & realisasi terpublikasi ≤ 7 hari setelah penetapan/perubahan |
| O-5 | Mempercepat penyusunan laporan ke supra-desa | Waktu susun laporan bulanan dari 2 hari → ≤ 2 jam (ekspor otomatis) |
| O-6 | Menyediakan kanal pengaduan yang terlacak | 100% pengaduan punya status & SLA; ≥ 80% selesai ≤ 14 hari |

> **ASUMSI:** Target O-1 bergantung pada tersedianya data awal (softcopy DTKS/Prodeskel
> atau buku induk yang dapat di-entri). Bila data awal hanya hardcopy, perlu paket
> **digitalisasi data** terpisah (lihat dokumen 11 §5).

## 3. Lingkup (Scope)

> **Lingkup instalasi:** sistem ini dibangun untuk **satu desa** (KEP-001). Bila kelak
> diperlukan untuk beberapa desa, jalurnya ada di 05 §10 dan 04 §4.2.

### 3.1 Masuk lingkup — MVP (Fase 1)
1. Manajemen wilayah administratif (dusun/RW/RT), profil desa & perangkat desa.
2. Modul kependudukan: penduduk, keluarga (KK), mutasi (lahir, mati, pindah datang,
   pindah keluar), riwayat perubahan data.
3. Layanan persuratan online: pengajuan oleh warga, verifikasi berjenjang,
   nomor surat otomatis, cetak PDF, QR verifikasi. Termasuk **katalog 22 jenis surat
   umum** (8 aktif saat pemasangan) — lihat [13 Katalog Surat](13-katalog-surat.md).
4. Portal publik: profil desa, berita, pengumuman, agenda, statistik penduduk agregat.
5. Pengaduan & aspirasi masyarakat dengan pelacakan status.
6. Manajemen pengguna, peran & hak akses (RBAC), audit log.
7. Dashboard ringkas untuk Kepala Desa.

### 3.2 Masuk lingkup — Fase 2
8. Transparansi keuangan desa (publikasi APBDes & realisasi, input manual/impor).
9. Buku administrasi desa digital (sesuai Permendagri 47/2016).
10. Aset & inventaris desa.
11. Data bantuan sosial & penerima program.
12. Notifikasi WhatsApp/email.

### 3.3 Masuk lingkup — Fase 3 (opsional)
13. Perencanaan pembangunan (RPJMDes/RKPDes, usulan musyawarah dusun).
14. Lapak desa / katalog UMKM & BUMDes.
15. Pemetaan GIS (fasilitas umum, sebaran kemiskinan, persil indikatif).
16. Aplikasi mobile (PWA → native bila diperlukan).

### 3.4 **Di luar lingkup (Out of Scope)**
Dinyatakan tegas untuk menghindari *scope creep*:

- **Bukan** pengganti SISKEUDES. Sistem ini **tidak** menerbitkan SPP, buku kas, atau
  dokumen pertanggungjawaban keuangan resmi; hanya mem-*publikasikan* dan
  me-*rekap* data dari SISKEUDES.
- **Bukan** pengganti SIAK/Dukcapil. Penerbitan KTP-el, KK, akta tetap kewenangan
  Dukcapil. Sistem hanya menyimpan salinan data untuk keperluan pelayanan desa.
- **Tidak** melakukan pencairan/transaksi keuangan (tidak ada *payment gateway*
  untuk retribusi pada Fase 1–2).
- **Tidak** menyediakan tanda tangan elektronik tersertifikasi (TTE BSrE) pada MVP —
  disiapkan *hook* integrasinya, aktivasi menunggu kesiapan sertifikat.
- Pengadaan perangkat (PC, printer, jaringan) dan pelatihan literasi digital dasar
  **tidak** termasuk, kecuali disepakati terpisah.

## 4. Pemangku Kepentingan (Stakeholder Register)

| Stakeholder | Peran dalam proyek | Kepentingan utama | Pengaruh |
|-------------|--------------------|-------------------|----------|
| Kepala Desa | **Sponsor / Project Owner** | Legitimasi, citra, kecepatan layanan | Tinggi |
| Sekretaris Desa | **Product Owner harian** | Kerapian administrasi, kemudahan laporan | Tinggi |
| Kaur Keuangan | Pengguna kunci modul keuangan | Akurasi, kesesuaian SISKEUDES | Sedang |
| Kasi Pemerintahan / Pelayanan | Pengguna kunci persuratan & penduduk | Beban kerja tidak bertambah | Tinggi |
| Kepala Dusun / RT / RW | Sumber & validator data lapangan | Alat kerja sederhana, bisa dari HP | Sedang |
| Operator Desa | Pengguna harian | Kecepatan input, minim klik | Sedang |
| Warga Desa | Pengguna akhir layanan | Layanan cepat, tidak perlu ke kantor | Sedang |
| BPD | Pengawas | Transparansi, akuntabilitas | Sedang |
| Camat / DPMD Kabupaten | Konsumen laporan | Kualitas & ketepatan waktu laporan | Sedang |
| Dukcapil | Pemilik data rujukan | Kepatuhan pemanfaatan data | Tinggi (regulatif) |
| Tim Pengembang | Pelaksana | Kejelasan kebutuhan | — |

## 5. Kriteria Sukses & Definition of Done (level proyek)

Proyek dinyatakan **selesai** bila seluruh berikut terpenuhi:

1. Semua kebutuhan prioritas **MUST** di dokumen 03 lolos UAT dengan status *Passed*.
2. Uji beban memenuhi NFR-PERF (dokumen 03 §5.1).
3. *Security review* (dokumen 08 §7) tidak menyisakan temuan **Critical/High** terbuka.
4. Dokumentasi pengguna (manual + video singkat per peran) diserahkan.
5. Pelatihan untuk ≥ 90% perangkat desa terlaksana dan tercatat.
6. Berjalan stabil 30 hari kalender dengan *uptime* ≥ 99% dan tanpa insiden Sev-1.
7. *Handover* kredensial, kode sumber, skema basis data, dan *runbook* operasional.

## 6. Asumsi, Ketergantungan & Batasan

**Asumsi**
- Tersedia koneksi internet di kantor desa (minimal 10 Mbps, boleh via seluler).
- Minimal 2 orang perangkat desa ditunjuk sebagai *champion* dan tersedia untuk
  sesi *requirement* dan UAT.
- Desa memiliki Peraturan Desa / SK Kades sebagai dasar hukum penyelenggaraan SID
  dan pengelolaan data pribadi warga.

**Ketergantungan**
- Ketersediaan data awal kependudukan dalam format digital.
- Persetujuan Dukcapil bila diinginkan integrasi *web service* NIK (opsional).
- Nama domain `desa.id` (melalui PANDI/pengelola) dan hosting.

**Batasan**
- Anggaran bersumber APBDes/Dana Desa → terikat tahun anggaran dan mekanisme
  pengadaan barang/jasa desa.
- Kapasitas SDM desa terbatas → sistem **harus** dapat dioperasikan tanpa
  pengetahuan teknis.
- Perangkat pengguna lapangan mayoritas **ponsel Android kelas bawah** → desain
  wajib *mobile-first* dan ringan.

## 7. Risiko Tingkat Proyek (ringkas)

| ID | Risiko | Dampak | Mitigasi |
|----|--------|--------|----------|
| R-1 | Kebocoran data pribadi warga (NIK, KK) | Sangat tinggi — pidana UU 27/2022 | Enkripsi, RBAC ketat, masking, audit log, *pen-test* |
| R-2 | Resistensi perangkat desa, kembali ke cara manual | Tinggi | *Champion user*, pendampingan 3 bulan, jalankan paralel 1 bulan |
| R-3 | Data awal kotor/duplikat | Tinggi | Validasi NIK/KK, laporan anomali, fase *data cleansing* |
| R-4 | Pergantian kepala desa mengubah prioritas | Sedang | Payung Perdes, dokumentasi lengkap, *handover* formal |
| R-5 | Anggaran tidak berlanjut untuk Fase 2–3 | Sedang | MVP dibuat berdiri sendiri & bermanfaat utuh |
| R-6 | Perubahan regulasi format laporan | Sedang | Template surat/laporan dibuat *configurable*, bukan *hardcode* |

Daftar risiko lengkap dengan skor dan *owner* ada di dokumen 11 §6.

## 8. Tata Kelola Proyek

- **Metodologi:** Agile–Scrum, sprint 2 minggu, dengan *milestone* fase tetap.
- **Ritual:** Sprint Planning, Daily 15′ (tim dev), Sprint Review bersama PO,
  Retrospective.
- **Forum keputusan:** Steering Committee (Kades, Sekdes, PM) — bulanan.
- **Kanal:** repositori Git (kode + dokumen), *issue tracker*, grup WhatsApp untuk
  komunikasi harian.

## 9. Prosedur Change Request

1. Usulan perubahan diajukan tertulis (form CR) oleh stakeholder mana pun.
2. BA/Architect menilai dampak: lingkup, jadwal, biaya, risiko (≤ 3 hari kerja).
3. CR dengan dampak ≤ 1 *story point* dan tanpa dampak jadwal → disetujui PO.
4. CR di atas itu → diputuskan Steering Committee.
5. CR disetujui → masuk *backlog*, versi dokumen terkait dinaikkan.
6. Tidak ada pekerjaan di luar *backlog* yang dikerjakan tanpa CR tercatat.

## 10. Persetujuan

| Nama | Jabatan | Peran | Tanda Tangan | Tanggal |
|------|---------|-------|--------------|---------|
| | Kepala Desa | Sponsor | | |
| | Sekretaris Desa | Product Owner | | |
| | | Project Manager | | |
