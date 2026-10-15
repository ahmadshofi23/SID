# 11 — Roadmap, Estimasi & Manajemen Risiko
**Proyek:** Sistem Informasi Desa (SID) · **Versi:** 2.0 · **Lingkup:** satu desa · **DB:** MySQL 8

---

## 1. Ringkasan Rencana

| Fase | Isi | Durasi | Hasil |
|------|-----|--------|-------|
| **Fase 0** | Discovery & perancangan rinci | 3 minggu | Dokumen final, *backlog* terurut, prototipe teruji |
| **Fase 1 — MVP** | Kependudukan, persuratan, portal, pengaduan, RBAC, laporan dasar | 14 minggu | **Sistem dapat dipakai sehari-hari** |
| **Fase 2** | Keuangan, aset, bantuan sosial, buku administrasi, notifikasi WA | 10 minggu | Administrasi desa lengkap |
| **Fase 3** | Perencanaan, lapak desa, GIS, TTE, aplikasi mobile | 12 minggu | Opsional, bergantung anggaran |
| **Total Fase 0–1** | | **17 minggu (±4 bulan)** | |
| **Total Fase 0–2** | | **27 minggu (±6,5 bulan)** | |

> **ASUMSI estimasi:** tim 4 orang penuh waktu (1 Tech Lead/BE, 1 BE, 1 FE,
> 1 QA/BA), dengan PM & desainer paruh waktu (50%), sprint 2 minggu, ketersediaan
> *Product Owner* dari desa minimal 4 jam/minggu. Setiap perubahan asumsi ini
> mengubah jadwal secara proporsional.

---

## 2. Rencana Sprint — Fase 0 & 1

### Fase 0 — Discovery (3 minggu, tanpa sprint)
| Minggu | Aktivitas | Keluaran |
|--------|-----------|----------|
| 1 | Wawancara 6 stakeholder; observasi langsung pelayanan di kantor desa (minimal 1 hari penuh); kumpulkan **contoh nyata** surat terbit desa — terutama untuk memastikan **pola penomoran** (13 §3) dan tata letak | Catatan proses AS-IS, foto/berkas contoh, pola nomor terkonfirmasi |
| 2 | Pemodelan proses TO-BE; finalisasi SRS; *profiling* data awal | SRS disetujui, laporan kualitas data |
| 3 | *Wireframe* & prototipe; **uji kebergunaan dengan 3 perangkat desa + 3 warga**; penyiapan repositori, CI, lingkungan | Prototipe teruji, *backlog* terurut, CI jalan |

**Gerbang Fase 0 → 1:** SRS ditandatangani PO · **pola penomoran surat** dan **pejabat
penanda tangan** terkonfirmasi dari arsip desa (13 §3–§4) · data awal tersedia atau
rencana digitalisasinya disetujui.

> Katalog 22 jenis surat sudah tersedia sebagai bawaan (KEP-003), sehingga gerbang ini
> **tidak lagi** menuntut desa menyusun daftar surat dari nol — hanya memverifikasi
> dua hal yang memang tidak bisa diasumsikan.

### Fase 1 — MVP (7 sprint × 2 minggu)

| Sprint | Fokus | Keluaran yang dapat diperagakan |
|--------|-------|--------------------------------|
| **S1** | Fondasi | Autentikasi + 2FA, RBAC, **lapis *repository* + aturan analisis statis** (04 §4.1), identitas desa & wilayah, *design token* & pustaka komponen dasar, audit log (+`REVOKE`), CI/CD ke staging |
| **S2** | Kependudukan inti | CRUD penduduk & KK, wilayah, tabel referensi, pencarian, riwayat perubahan, *masking* data |
| **S3** | Data & mutasi | Impor massal Excel (dengan pra-validasi), mutasi 4 jenis, laporan anomali, statistik agregat + tabel ringkasan `population_stats` |
| **S4** | Persuratan I | Jenis surat + **seeder katalog 22 surat** (dok. 13), template WYSIWYG, formulir dinamis, pengajuan *walk-in*, mesin status, **penomoran otomatis** |
| **S5** | Persuratan II | Render PDF + QR, unggah berkas syarat, pelacakan status (verifikasi publik, alur berjenjang, arsip, dan pembatalan sudah selesai di S4 — lihat KEP-013) |
| **S6** | Portal & warga | Portal publik (profil, berita, pengumuman, agenda, layanan, statistik), registrasi & verifikasi akun warga, pengajuan daring, PWA |
| **S7** | Pengaduan, laporan, stabilisasi | Pengaduan + penugasan + SLA, dashboard, laporan & ekspor, uji beban, uji penetrasi, perbaikan temuan |

**Milestone:**
| Kode | Milestone | Setelah |
|------|-----------|---------|
| M1 | Fondasi aman siap (RBAC + pembatasan wilayah/keluarga teruji) | S1 |
| M2 | Data penduduk dapat dimasukkan & dipercaya | S3 |
| M3 | **Surat dapat diterbitkan ujung-ke-ujung** (milestone terpenting) | S5 |
| M4 | Warga dapat melayani diri sendiri | S6 |
| M5 | Siap UAT | S7 |

### Pasca Fase 1 — Transisi ke operasional (4 minggu, paralel)
| Minggu | Aktivitas |
|--------|-----------|
| 1 | UAT (dokumen 09 §6) + perbaikan temuan |
| 2 | Migrasi data produksi + verifikasi sampel 5% oleh perangkat desa |
| 3 | Pelatihan per peran (2 sesi) + penyusunan manual & video singkat |
| 4 | **Operasi paralel** (manual + sistem), lalu *go-live* bertahap |

> **Catatan:** *go-live* dilakukan **per modul**, bukan sekaligus. Mulai dari modul
> kependudukan (risiko terendah), lalu persuratan *walk-in*, baru pengajuan daring
> oleh warga. Menyalakan semuanya pada hari yang sama adalah penyebab kegagalan
> paling umum pada proyek sejenis.

---

## 3. Estimasi Upaya (Story Point & Hari-Orang)

| Modul | SP | Hari-orang | Catatan |
|-------|----|-----------|---------|
| Fondasi (auth, RBAC, lapis *repository*, audit) | 47 | 24 | Jangan dipangkas — seluruh keamanan bertumpu di sini. **−8 SP** dari v1.0 karena tanpa multi-tenancy (KEP-001) |
| Design system & komponen | 21 | 11 | Investasi; mempercepat sprint berikutnya |
| Kependudukan (CRUD, cari, riwayat) | 55 | 28 | |
| Impor/ekspor + kualitas data | 34 | 17 | Sering diremehkan; validasi adalah bagian terberat |
| Mutasi penduduk | 21 | 11 | |
| Statistik & tabel ringkasan + *job* penyegar | 13 | 7 | |
| Persuratan (jenis, template, formulir dinamis) | 55 | 28 | Formulir dinamis = bagian paling rumit |
| Seeder katalog 22 jenis surat + template (dok. 13) | 13 | 7 | **Baru** (KEP-003) — menggantikan penantian daftar surat dari desa |
| Persuratan (alur, nomor, PDF, QR, arsip) | 55 | 28 | |
| Portal publik | 34 | 17 | |
| Portal & registrasi warga | 34 | 17 | Verifikasi identitas memerlukan alur manual |
| Pengaduan | 21 | 11 | |
| Dashboard & laporan | 34 | 17 | |
| Administrasi sistem & pengaturan | 21 | 11 | |
| Pengerasan keamanan & perbaikan temuan | 21 | 11 | |
| Pengujian & otomasi (di luar DoD) | 34 | 17 | Uji otorisasi & E2E. **Tidak dikurangi** walau lingkup menyusut — tanpa RLS, uji otorisasi justru lebih penting |
| DevOps & infrastruktur | 21 | 11 | |
| Dokumentasi & pelatihan | 21 | 11 | |
| **Subtotal Fase 1** | **555** | **283** | |
| **Cadangan risiko 20%** | **111** | **57** | *Wajib ada* — bukan padding |
| **Total Fase 1** | **666 SP** | **± 340 hari-orang** | ≈ 4 orang × 17 minggu |

> Keputusan KEP-001 menghemat ± 8 SP di fondasi tetapi KEP-003 menambah ± 13 SP
> untuk katalog surat — jadi **total praktis tidak berubah**. Yang berubah adalah
> **risikonya**: Sprint 4–5 tidak lagi menunggu daftar surat dari desa, dan kelas bug
> kebocoran lintas-desa hilang.

**Fase 2** perkiraan **380 SP** (± 190 hari-orang) · **Fase 3** perkiraan
**450 SP** (± 225 hari-orang), akan dirinci ulang setelah Fase 1 karena ketergantungan
pada hasil nyata Fase 1.

### Hal yang paling sering membuat estimasi melebar (waspadai sejak awal)
1. **Template surat.** Katalog bawaan (dok. 13) menutup sebagian besar risiko ini,
   tetapi tata letak tiap desa tetap bisa berbeda. Tetapkan batas: penyesuaian di luar
   8 surat aktif dihitung pekerjaan tambahan (± 0,5 hari-orang/template).
2. **Kualitas data awal.** Data kotor dapat memakan 2–4 minggu di luar perkiraan.
3. **Verifikasi identitas warga.** Alur manual yang sering diremehkan.
4. **Ketersediaan PO.** Keputusan yang menggantung adalah penyebab penundaan terbesar
   yang paling tidak terlihat.
5. **Format laporan ke supra-desa.** Sering baru diminta di akhir, dengan format
   yang berbeda dari perkiraan.

---

## 4. Prioritas bila Anggaran/Waktu Terpangkas

Bila harus memangkas, pangkas **dari bawah** daftar ini. Lima teratas adalah inti
yang membuat sistem bermanfaat; tanpa salah satunya, sistem tidak layak dirilis.

| # | Kemampuan | Boleh dipangkas? |
|---|-----------|------------------|
| 1 | Autentikasi, RBAC, audit log, isolasi tenant | **Tidak, mutlak** |
| 2 | Kependudukan + impor + mutasi | **Tidak** |
| 3 | Persuratan *walk-in* + nomor otomatis + PDF | **Tidak** |
| 4 | Portal publik (profil, berita, standar pelayanan) | **Tidak** (kewajiban keterbukaan informasi) |
| 5 | Cadangan + pemantauan | **Tidak** |
| 6 | Pengajuan daring oleh warga | Dapat ditunda → Fase 1.5 |
| 7 | Pengaduan | Dapat ditunda |
| 8 | Dashboard & laporan lanjutan | Sederhanakan |
| 9 | Alur persetujuan berjenjang | Sederhanakan jadi 1 tahap |
| 10 | PWA / mode luring | Tunda |
| 11 | Statistik interaktif | Ganti tabel statis |
| 12 | Modul Fase 2 & 3 | Tunda seluruhnya |

**Versi minimum yang tetap layak rilis** (bila anggaran sangat terbatas):
butir 1–5 saja ≈ **9 sprint total termasuk Fase 0** (± 10 minggu, ± 180 hari-orang).

---

## 5. Paket Pekerjaan Tambahan (Opsional, di luar estimasi inti)

| Paket | Isi | Perkiraan |
|-------|-----|-----------|
| **Digitalisasi data awal** | Entri 5.000 penduduk dari hardcopy + verifikasi | 20–30 hari-orang (atau kontrak operator terpisah) |
| **Uji penetrasi pihak ketiga** | Uji + laporan + verifikasi perbaikan | Rp 15–40 jt (sekali) |
| **Pelatihan tambahan** | Sesi per dusun untuk RT/RW | 1 hari-orang per sesi |
| **Pendampingan pasca go-live** | 3 bulan, respons ≤ 1 hari kerja | 4 hari-orang/bulan |
| **Template surat tambahan** | Di luar 8 prioritas | 0,5 hari-orang/template |
| **Integrasi Dukcapil** | Setelah PKS terbit | 15 hari-orang + jalur legal |
| **Modul GIS** | Peta persil & fasilitas umum | 25 hari-orang |
| **Pemeliharaan tahunan** | Pembaruan keamanan, perbaikan bug, dukungan | 15–20% nilai pengembangan per tahun |

> Angka hari-orang bersifat indikatif untuk perencanaan; nilai rupiah bergantung
> tarif penyedia dan mekanisme pengadaan desa.

---

## 6. Daftar Risiko (Risk Register)

Skor = Probabilitas (1–5) × Dampak (1–5).

| ID | Risiko | P | D | Skor | Mitigasi | Rencana kontinjensi | Pemilik |
|----|--------|---|---|------|----------|---------------------|---------|
| R-1 | **Kebocoran data pribadi warga** | 2 | 5 | **10** | Dokumen 08 seluruhnya; uji penetrasi; enkripsi kolom; uji otorisasi otomatis | Prosedur tanggap insiden (08 §9); asuransi/jaminan vendor | Tech Lead |
| R-2 | Perangkat desa kembali ke cara manual | 3 | 4 | **12** | *Champion user*; operasi paralel 1 bulan; UX diuji dengan pengguna nyata; pendampingan 3 bulan | Perdes yang mewajibkan penggunaan sistem; evaluasi ulang UX | PO |
| R-3 | Data awal kotor/tidak lengkap | 4 | 3 | **12** | *Profiling* di Fase 0; validasi impor; laporan anomali; verifikasi RT | Paket digitalisasi terpisah; *go-live* bertahap per dusun | BA |
| R-4 | Lingkup melebar (*scope creep*) | 4 | 3 | **12** | Dokumen 02 §4.6 (daftar Won't) eksplisit; prosedur CR; *backlog* terurut | Tunda ke fase berikutnya; negosiasi ulang jadwal | PM |
| R-5 | PO/desa tidak tersedia untuk keputusan | 3 | 4 | **12** | Jadwal tetap mingguan; batas waktu keputusan 3 hari kerja; wakil PO ditunjuk | Keputusan bawaan diambil PM + dicatat sebagai asumsi | PM |
| R-6 | Template surat desa berbeda dari katalog bawaan | 2 | 3 | 6 | Katalog bawaan (dok. 13) + template HTML *configurable*; verifikasi dengan surat asli di Fase 0 | Batasi 8 surat aktif; sisanya paket tambahan | BA |
| R-15 | **Bug otorisasi lolos** karena tidak ada RLS (konsekuensi KEP-002) | 3 | 5 | **15** | Lapis *repository* wajib + aturan analisis statis di CI + uji otorisasi penuh (09 §4.3) yang memblokir rilis | Tanggap insiden (08 §9); audit kode otorisasi oleh pihak ketiga | Tech Lead |
| R-16 | **Pola penomoran salah** dan baru disadari setelah surat terbit | 3 | 3 | 9 | Konfirmasi dari arsip surat asli di Fase 0; terbitkan surat uji per jenis sebelum go-live (13 §8) | Berita acara koreksi penomoran; deret baru mulai periode berikutnya | BA |
| R-7 | Anggaran Fase 2–3 tidak berlanjut | 3 | 3 | 9 | MVP dirancang bermanfaat utuh dan berdiri sendiri | Serah terima lengkap agar desa dapat melanjutkan sendiri | Sponsor |
| R-8 | Internet kantor desa tidak stabil | 3 | 3 | 9 | PWA, *draft* lokal, *payload* kecil, toleran latensi | Modem cadangan; mode entri *batch* | Tech Lead |
| R-9 | Perubahan regulasi format laporan/surat | 2 | 3 | 6 | Template & laporan *configurable*, bukan *hardcode* | Paket penyesuaian regulasi | BA |
| R-10 | Pergantian kepala desa mengubah prioritas | 2 | 4 | 8 | Perdes sebagai payung; dokumentasi lengkap; manfaat terukur dipublikasikan | Presentasi ulang nilai sistem ke pimpinan baru | Sponsor |
| R-11 | Anggota tim kunci keluar | 2 | 4 | 8 | Dokumentasi, *pair programming*, tidak ada "satu-satunya yang tahu" | Masa tumpang tindih serah terima 2 minggu | PM |
| R-12 | Ketergantungan pihak ketiga (WA gateway, S3) putus | 2 | 3 | 6 | Abstraksi lapis penyedia; notifikasi bukan jalur kritis | Alih ke penyedia lain; kembali ke email/notifikasi dalam aplikasi | Tech Lead |
| R-13 | Nomor surat ganda/terlewat lolos ke produksi | 2 | 5 | **10** | ADR-001 + TC-SRT-08 uji konkurensi wajib di CI | Prosedur koreksi manual + berita acara; audit nomor berkala | Tech Lead |
| R-14 | *Go-live* bersamaan seluruh modul menimbulkan kekacauan | 3 | 4 | **12** | *Go-live* bertahap per modul (§2); operasi paralel | Kembali ke manual untuk modul bermasalah tanpa mematikan yang lain | PM |

**Tinjauan risiko:** setiap Sprint Review. Risiko dengan skor ≥ 10 dibahas di
Steering Committee bulanan beserta status mitigasinya.

---

## 7. Metrik Keberhasilan Pasca Go-Live

Diukur pada bulan ke-1, 3, 6, dan 12 — dan **dipublikasikan ke stakeholder**.

| Metrik | Dasar (sebelum) | Target 3 bln | Target 12 bln |
|--------|-----------------|--------------|---------------|
| Waktu rata-rata penerbitan surat | 2 hari | ≤ 4 jam | ≤ 1 jam |
| Persentase pengajuan via kanal daring | 0% | 15% | 40% |
| Penduduk ter-input & terverifikasi | — | 90% | 98% |
| Surat diterbitkan lewat sistem | 0% | 80% | 100% |
| Pengaduan dengan status terlacak | 0% | 100% | 100% |
| Pengaduan selesai ≤ 14 hari kerja | — | 60% | 80% |
| Waktu susun laporan bulanan | 2 hari | 4 jam | ≤ 2 jam |
| *Uptime* bulanan | — | ≥ 99% | ≥ 99,5% |
| Kunjungan portal/bulan | — | 500 | 2.000 |
| Kepuasan perangkat desa (1–5) | — | ≥ 3,8 | ≥ 4,2 |
| Kepuasan warga pengguna layanan (1–5) | — | ≥ 3,8 | ≥ 4,2 |
| Insiden keamanan | — | **0** | **0** |

---

## 8. Keputusan yang Perlu Diambil Sekarang

Agar Fase 0 dapat dimulai, lima keputusan berikut harus diputuskan lebih dulu —
masing-masing berdampak langsung pada jadwal dan biaya:

### Sudah diputuskan ✓
| # | Keputusan | Hasil | Rujukan |
|---|-----------|-------|---------|
| 1 | Ruang lingkup | **Satu desa** (single-tenant) | KEP-001 |
| 2 | Basis data | **MySQL 8.0** | KEP-002 |
| 3 | Jenis surat | **Katalog umum**, 8 aktif + 14 tersedia | KEP-003, dok. 13 |
| 4 | Stack aplikasi | Laravel 11 + MySQL 8 + Inertia/React | KEP-004 |

### Masih terbuka — perlu diputuskan sebelum sprint terkait
| # | Keputusan | Pilihan | Dibutuhkan paling lambat |
|---|-----------|---------|--------------------------|
| 5 | **Model hosting** | VPS penyedia (Model A di 10 §1) / on-premise kabupaten | Sebelum S1 selesai (CI/CD ke staging) |
| 6 | **Sumber & kondisi data awal** | Digital / hardcopy / tidak ada | Sebelum S3 (modul impor) — menentukan perlu-tidaknya paket digitalisasi (§5) |
| 7 | **Pola penomoran surat** (ambil dari surat asli desa) | Pola A / B / C di 13 §3 | Sebelum surat pertama terbit (akhir S5) ⚠ |
| 8 | **Pejabat penanda tangan** per jenis surat | Kades saja / Kades + Sekdes | Sebelum S5 |

Nomor 7 adalah yang paling mudah terlewat dan paling mahal diperbaiki: mengubah pola
penomoran setelah ada surat terbit menghasilkan deret yang tidak konsisten di arsip desa.

Tiga hal berikut tidak memblokir pengembangan tetapi **wajib** siap sebelum
*go-live*: Perdes/SK payung hukum, penunjukan penanggung jawab pelindungan data,
dan halaman Kebijakan Privasi.
