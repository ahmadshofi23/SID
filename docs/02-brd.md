# 02 — Business Requirements Document (BRD)
**Proyek:** Sistem Informasi Desa (SID) · **Versi:** 2.0 · **Penyusun:** Business Analyst
**Lingkup:** satu desa (KEP-001)

---

## 1. Tujuan Dokumen
Menjelaskan **kebutuhan bisnis** (apa dan mengapa), bukan solusi teknis. Dokumen ini
menjadi sumber kebenaran saat terjadi perbedaan tafsir antara pengguna dan pengembang.
Spesifikasi teknisnya diturunkan di dokumen 03 (SRS).

## 2. Kerangka Regulasi Acuan

| Regulasi | Relevansi terhadap sistem |
|----------|---------------------------|
| UU No. 6/2014 tentang Desa | Kewenangan desa, kewajiban keterbukaan informasi |
| UU No. 25/2009 tentang Pelayanan Publik | Wajib ada standar pelayanan, SLA, kanal pengaduan |
| UU No. 14/2008 tentang Keterbukaan Informasi Publik | Informasi wajib diumumkan berkala (termasuk anggaran) |
| UU No. 23/2006 jo. UU No. 24/2013 tentang Administrasi Kependudukan | Pembatasan pemanfaatan data kependudukan |
| **UU No. 27/2022 tentang Pelindungan Data Pribadi (PDP)** | Dasar hukum pemrosesan, hak subjek data, sanksi |
| UU No. 11/2008 jo. UU No. 19/2016 jo. UU No. 1/2024 (ITE) | Keabsahan dokumen & tanda tangan elektronik |
| Perpres No. 95/2018 tentang SPBE | Standar arsitektur & interoperabilitas |
| Perpres No. 39/2019 tentang Satu Data Indonesia | Prinsip data: standar, metadata, interoperabel, kode referensi |
| Permendagri No. 47/2016 tentang Administrasi Pemerintahan Desa | **Bentuk & isi buku administrasi desa** → acuan struktur data |
| Permendagri No. 20/2018 tentang Pengelolaan Keuangan Desa | Struktur APBDes (pendapatan, belanja, pembiayaan) |

> **CATATAN:** Daftar di atas adalah acuan kerja. Nomor dan substansi wajib
> diverifikasi ulang oleh Bagian Hukum/DPMD sebelum *sign-off*, karena regulasi
> turunan sering diperbarui.

## 3. Analisis Proses Bisnis

### 3.1 Proses layanan surat — **AS-IS**

```
Warga                 Meja Pelayanan        Kasi/Kaur          Sekdes        Kades
  │                         │                   │                 │             │
  ├─ datang ke kantor ─────►│                   │                 │             │
  │                         ├─ cek berkas       │                 │             │
  │                         ├─ tulis manual di  │                 │             │
  │                         │  buku & Word ────►│                 │             │
  │                         │                   ├─ periksa ──────►│             │
  │                         │                   │                 ├─ paraf ────►│
  │                         │                   │                 │             ├─ TTD
  │◄──── pulang, kembali besok ─────────────────────────────────────────────────┤
```
**Masalah terukur (hasil observasi/wawancara — diisi saat *discovery*):**
| Titik nyeri | Dampak |
|---|---|
| Penulisan ulang data warga dari buku induk | 5–10 menit/surat, rawan salah ketik |
| Nomor surat dicatat manual | Nomor ganda/terlewat |
| Pejabat penanda tangan tidak di tempat | Tunda 1–3 hari |
| Tidak ada arsip digital | Surat lama sulit ditemukan; tidak ada rekap |
| Warga tidak tahu status | Telepon/datang berulang |

### 3.2 Proses layanan surat — **TO-BE**

```
Warga (web/HP)        Sistem              Verifikator (Kasi)      Penandatangan
  │                     │                        │                      │
  ├─ login & pilih ────►│                        │                      │
  │  jenis surat        ├─ data penduduk         │                      │
  │                     │  terisi otomatis       │                      │
  ├─ unggah syarat ────►│                        │                      │
  │                     ├─ validasi otomatis     │                      │
  │                     │  (kelengkapan, syarat) │                      │
  │                     ├─ notifikasi ──────────►│                      │
  │                     │                        ├─ verifikasi/tolak    │
  │                     │                        │  + catatan           │
  │                     ├─ nomor surat otomatis ─┴─ notifikasi ────────►│
  │                     │                                               ├─ setujui/TTD
  │◄─ notif: siap ──────┤◄─ PDF + QR verifikasi ────────────────────────┤
  ├─ unduh / ambil      │
```
**Perubahan kunci:** entri data sekali (*single source of truth*), penomoran otomatis,
status transparan, arsip digital permanen, persetujuan dapat dilakukan dari ponsel.

### 3.3 Proses pengaduan masyarakat — TO-BE (ringkas)
`Lapor (dengan/tanpa identitas) → Triase oleh Admin → Penugasan ke Kaur/Kasi terkait →
Tindak lanjut + bukti → Verifikasi Sekdes → Selesai & publikasi ringkasan`
SLA: triase ≤ 2 hari kerja, penyelesaian ≤ 14 hari kerja, eskalasi otomatis bila lewat.

### 3.4 Proses transparansi keuangan — TO-BE (ringkas)
`SISKEUDES (sumber resmi) → ekspor/impor atau entri rekap → review Kaur Keuangan →
persetujuan Sekdes → publikasi di portal (infografis + tabel + unduh)`
Prinsip: sistem ini **hilir**, bukan sumber. Tidak ada angka keuangan yang
dipublikasikan tanpa persetujuan eksplisit.

## 4. Kebutuhan Bisnis (Business Requirements)

Prioritas memakai **MoSCoW**: M=Must, S=Should, C=Could, W=Won't (fase ini).

### 4.1 Kependudukan & Wilayah
| ID | Kebutuhan Bisnis | Prioritas | Fase |
|----|------------------|-----------|------|
| BR-01 | Desa dapat mengelola satu basis data penduduk & keluarga yang menjadi acuan tunggal semua layanan | M | 1 |
| BR-02 | Setiap perubahan data penduduk terekam siapa, kapan, dan nilai sebelumnya | M | 1 |
| BR-03 | Desa dapat mencatat mutasi penduduk (lahir, mati, pindah datang, pindah keluar) sehingga statistik selalu mutakhir | M | 1 |
| BR-04 | Struktur wilayah (dusun/RW/RT) dapat dikonfigurasi sesuai kondisi desa | M | 1 |
| BR-05 | Data dapat diimpor massal dari berkas Excel/CSV dengan laporan baris gagal | M | 1 |
| BR-06 | Sistem menandai anomali data (NIK ganda, umur tidak wajar, KK tanpa kepala keluarga) | S | 1 |
| BR-07 | Kepala dusun/RT dapat mengusulkan koreksi data dari ponsel, disetujui admin | S | 2 |

### 4.2 Pelayanan Administrasi (Persuratan)
| ID | Kebutuhan Bisnis | Prioritas | Fase |
|----|------------------|-----------|------|
| BR-10 | Warga dapat mengajukan surat secara daring tanpa datang ke kantor | M | 1 |
| BR-11 | Warga dapat memantau status pengajuan dan alasan penolakan | M | 1 |
| BR-12 | Jenis surat, syarat, dan isi template dapat diubah petugas **tanpa bantuan programmer**. Sistem dikirim dengan katalog surat umum siap pakai (dok. 13) | M | 1 |
| BR-13 | Nomor surat terbit otomatis, unik, berurutan, dan sesuai pola penomoran desa | M | 1 |
| BR-14 | Surat terbit sebagai PDF dengan QR untuk verifikasi keaslian oleh pihak ketiga | M | 1 |
| BR-15 | Alur persetujuan berjenjang dapat dikonfigurasi per jenis surat | S | 1 |
| BR-16 | Petugas dapat melayani warga yang datang langsung (*walk-in*) di sistem yang sama | M | 1 |
| BR-17 | Surat dapat ditandatangani elektronik tersertifikasi (TTE BSrE) | C | 3 |
| BR-18 | Rekap layanan per jenis/periode/petugas tersedia untuk laporan | M | 1 |

### 4.3 Keterbukaan Informasi & Portal Publik
| ID | Kebutuhan Bisnis | Prioritas | Fase |
|----|------------------|-----------|------|
| BR-20 | Desa memiliki situs resmi berisi profil, struktur organisasi, berita, pengumuman, agenda | M | 1 |
| BR-21 | Statistik penduduk tersaji agregat **tanpa membuka data pribadi** | M | 1 |
| BR-22 | APBDes & realisasinya dipublikasikan dalam bentuk mudah dipahami (infografis) | M | 2 |
| BR-23 | Portal memuat standar pelayanan: syarat, biaya, waktu penyelesaian | M | 1 |
| BR-24 | Portal dapat diakses baik dari ponsel maupun koneksi lambat | M | 1 |
| BR-25 | Katalog produk UMKM/BUMDes desa | C | 3 |

### 4.4 Partisipasi Masyarakat
| ID | Kebutuhan Bisnis | Prioritas | Fase |
|----|------------------|-----------|------|
| BR-30 | Warga dapat menyampaikan pengaduan/aspirasi dan melacak tindak lanjutnya | M | 1 |
| BR-31 | Pengaduan dapat diajukan anonim bila menyangkut hal sensitif | S | 1 |
| BR-32 | Usulan pembangunan dari musyawarah dusun tercatat dan dapat dilacak sampai RKPDes | C | 3 |

### 4.5 Administrasi Internal & Tata Kelola
| ID | Kebutuhan Bisnis | Prioritas | Fase |
|----|------------------|-----------|------|
| BR-40 | Hak akses dibatasi sesuai jabatan; operator tidak melihat data yang tidak diperlukan | M | 1 |
| BR-41 | Semua tindakan pada data sensitif terekam (audit log) dan tidak dapat dihapus pengguna | M | 1 |
| BR-42 | Buku administrasi desa (umum, penduduk, keuangan, pembangunan) tersedia digital & dapat dicetak sesuai format Permendagri 47/2016 | S | 2 |
| BR-43 | Inventaris aset & kekayaan desa tercatat beserta kondisi dan nilainya | S | 2 |
| BR-44 | Data penerima bantuan sosial tercatat & dapat diverifikasi berlapis (RT → Kadus → Kasi Kesra) | S | 2 |
| BR-45 | Laporan rutin ke kecamatan/DPMD dapat diekspor otomatis (Excel/PDF) | M | 1 |
| BR-46 | Pencadangan data otomatis dan dapat dipulihkan | M | 1 |

### 4.6 Tidak dikerjakan pada rilis ini (Won't)
| ID | Kebutuhan | Alasan |
|----|-----------|--------|
| BR-W1 | Pembayaran retribusi/PBB daring | Perlu dasar hukum & rekening desa; risiko tinggi |
| BR-W2 | Pembukuan keuangan penuh (jurnal, buku kas) | Duplikasi SISKEUDES; risiko temuan audit |
| BR-W3 | Penerbitan dokumen kependudukan (KTP/KK/akta) | Bukan kewenangan desa |
| BR-W4 | Chatbot AI layanan warga | Nilai tambah rendah dibanding risiko salah informasi |

## 5. Peran Pengguna & Kewenangan (Business View)

| Peran | Deskripsi | Kewenangan inti |
|-------|-----------|-----------------|
| **Admin Teknis** (penyedia) | Pemeliharaan server & pemutakhiran versi | Akses *server*, bukan peran di dalam aplikasi. **Tidak** memiliki akun dengan hak melihat data warga; akses darurat dilakukan dengan sepengetahuan Admin Desa dan tercatat |
| **Admin Desa** (umumnya Sekdes) | Penanggung jawab data desa | Semua modul dalam desanya, kelola pengguna desa, konfigurasi template |
| **Kepala Desa** | Pimpinan | Dashboard, persetujuan/tanda tangan, lihat semua, **tidak** input harian |
| **Kaur/Kasi** | Pelaksana teknis per bidang | Modul sesuai bidang (Pemerintahan, Kesra, Pelayanan, Keuangan, Perencanaan) |
| **Kepala Dusun** | Wilayah | Lihat & usul koreksi data penduduk dusunnya; verifikasi lapangan |
| **Operator/Staf** | Entri data | Input penduduk & layanan; **tidak** boleh hapus atau menyetujui |
| **Warga (terdaftar)** | Pemohon layanan | Lihat & ajukan untuk dirinya/keluarganya; **hanya** data sendiri |
| **Publik (anonim)** | Pengunjung | Informasi publik & statistik agregat saja |
| **Pengawas** (BPD/Camat/Auditor) | Pemantau | **Read-only** lintas modul + ekspor laporan |

Matriks hak akses teknis per aksi (CRUD) ada di dokumen 03 §4.9.

## 6. Aturan Bisnis (Business Rules)

| ID | Aturan |
|----|--------|
| RULE-01 | NIK bersifat unik 16 digit dalam satu tenant; satu NIK hanya boleh terdaftar pada satu penduduk aktif |
| RULE-02 | Satu keluarga (nomor KK) wajib memiliki tepat satu penduduk berstatus **Kepala Keluarga** |
| RULE-03 | Penduduk berstatus **Meninggal** atau **Pindah Keluar** dikecualikan dari semua statistik dan tidak dapat menjadi pemohon surat |
| RULE-04 | Nomor surat tidak boleh digunakan ulang; nomor terbit **hanya** saat surat disetujui, bukan saat diajukan |
| RULE-05 | Surat yang sudah terbit tidak dapat diubah; koreksi dilakukan dengan pembatalan + penerbitan surat baru yang merujuk surat lama |
| RULE-06 | Pengguna tidak dapat menyetujui pengajuan yang dibuatnya sendiri (*segregation of duties*), kecuali layanan *walk-in* yang dikonfigurasi khusus |
| RULE-07 | Data pribadi (NIK, no. KK, tanggal lahir) ditampilkan ter-masking (`3201••••••••1234`) bagi peran yang tidak berkepentingan langsung |
| RULE-08 | Penghapusan data penduduk hanya *soft delete*; penghapusan permanen hanya oleh Admin Desa dengan alasan tercatat |
| RULE-09 | Pengaduan anonim tidak menyimpan identitas pelapor, namun tetap menyimpan kode pelacakan |
| RULE-10 | Angka keuangan hanya tampil di portal publik setelah berstatus **Dipublikasikan** oleh Sekdes/Kades |
| RULE-11 | Warga hanya dapat mengakses data dirinya dan anggota keluarga dalam satu KK yang sama |
| RULE-12 | Masa retensi: data layanan diarsipkan setelah 5 tahun; audit log disimpan minimal 5 tahun dan tidak dapat diubah |

## 7. Kebutuhan Data & Pelaporan

**Laporan wajib (Fase 1):**
1. Rekapitulasi penduduk per dusun/RT: jenis kelamin, kelompok umur, pendidikan,
   pekerjaan, agama, status perkawinan.
2. Laporan mutasi penduduk bulanan.
3. Rekap layanan surat per jenis & periode (termasuk waktu penyelesaian rata-rata).
4. Rekap pengaduan per kategori & status.
5. Buku Induk Penduduk & Buku Mutasi (format Permendagri 47/2016).

Semua laporan: tampil di layar, ekspor **Excel** dan **PDF**, dengan filter periode & wilayah.

## 8. Kriteria Penerimaan Bisnis (Sampel)

**BR-10 — Pengajuan surat daring**
- GIVEN warga terdaftar & terverifikasi, WHEN memilih jenis surat dan mengisi formulir,
  THEN data identitas terisi otomatis dari basis data penduduk dan tidak dapat diubah warga.
- GIVEN syarat wajib belum diunggah, WHEN warga menekan *Kirim*, THEN sistem menolak
  dan menyebutkan syarat yang kurang.
- GIVEN pengajuan terkirim, THEN warga menerima nomor tiket dan dapat melihat status
  tanpa harus menghubungi kantor.

**BR-13 — Penomoran surat**
- GIVEN dua petugas menyetujui dua surat pada saat yang sama, THEN masing-masing
  mendapat nomor berbeda dan berurutan, tanpa nomor terlewat.
- GIVEN tahun berganti, THEN penomoran kembali ke 1 dengan komponen tahun yang benar.

**BR-21 — Statistik tanpa data pribadi**
- GIVEN pengunjung anonim membuka halaman statistik, THEN tidak ada NIK, nama, atau
  alamat lengkap yang dapat diakses, termasuk melalui API/*view-source*.
- GIVEN suatu kelompok hanya berisi < 5 orang, THEN angka disamarkan untuk mencegah
  identifikasi ulang (*re-identification*).

## 9. Dampak Organisasi & Manajemen Perubahan

| Aspek | Kondisi sekarang | Setelah sistem | Tindakan yang diperlukan |
|-------|------------------|----------------|--------------------------|
| Entri data | Tiap bidang punya file sendiri | Satu basis data | SK penunjukan **pemilik data** per modul |
| Pengesahan surat | Tanda tangan basah di kantor | Persetujuan digital dari mana saja | Perdes/SK legalitas dokumen elektronik |
| Jam layanan | Jam kerja | Pengajuan 24/7, proses jam kerja | Publikasi SLA baru di portal |
| Kompetensi | Word/Excel | Aplikasi web | Pelatihan 2 sesi + pendampingan 3 bulan |
| Arsip | Lemari berkas | Digital + cadangan | Prosedur arsip & retensi |

**Rencana transisi:** jalankan **paralel** (manual + sistem) selama 1 bulan pertama,
lalu *cut-over* penuh setelah akurasi data terverifikasi ≥ 98%.

## 10. Lampiran — Pertanyaan Terbuka untuk Discovery

Wajib dijawab sebelum SRS di-*freeze*:
1. ~~Berapa jenis surat yang dilayani?~~ **Terjawab (KEP-003):** katalog 22 jenis
   disediakan, 8 aktif. Yang perlu diverifikasi: apakah ada jenis surat khas desa ini
   yang **tidak** ada di katalog dokumen 13.
2. **Pola penomoran surat yang berlaku — contoh nyata, bukan asumsi?** ⚠ *masih terbuka*
3. Siapa penanda tangan untuk tiap jenis surat — selalu Kades, atau dapat Sekdes? ⚠ *masih terbuka*
4. Apakah tersedia data penduduk digital? Format apa, kapan terakhir diperbarui?
5. Apakah desa sudah punya domain/hosting, atau mengikuti layanan kabupaten?
6. Apakah ada sistem lain yang wajib diintegrasikan (OpenSID, SISKEUDES, SIPD, Prodeskel)?
7. Siapa yang akan menjadi admin & *champion* setelah serah terima?
8. Apakah sudah ada kebijakan/persetujuan pemrosesan data pribadi warga?
