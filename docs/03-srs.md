# 03 — Software Requirements Specification (SRS)
**Proyek:** Sistem Informasi Desa (SID) · **Versi:** 2.0
**Acuan:** IEEE 830 (disesuaikan) · **Turunan dari:** dokumen 02 (BRD)
**Lingkup:** satu desa (KEP-001) · **Basis data:** MySQL 8 (KEP-002)

> **Perubahan dari v1.0:** peran **Super Admin (SA)** dan seluruh kebutuhan terkait
> multi-tenancy dihapus (KEP-001). Jenis surat kini punya katalog bawaan — lihat
> [13 Katalog Surat](13-katalog-surat.md) (KEP-003).

---

## 1. Pendahuluan

### 1.1 Tujuan
Menerjemahkan kebutuhan bisnis (BRD) menjadi spesifikasi yang dapat diimplementasikan
dan diuji. Setiap kebutuhan fungsional (FR) tertelusur ke kebutuhan bisnis (BR) dan
ke kasus uji (TC) di dokumen 09.

### 1.2 Definisi Peran Singkat
`AD` Admin Desa · `KD` Kepala Desa · `KK` Kaur/Kasi ·
`KS` Kepala Dusun · `OP` Operator · `WG` Warga terdaftar · `PB` Publik anonim ·
`PW` Pengawas (read-only)

### 1.3 Notasi Prioritas
`M` Must have · `S` Should have · `C` Could have

---

## 2. Deskripsi Umum Sistem

### 2.1 Perspektif Produk
Aplikasi web **satu desa** (single-tenant) dengan tiga muka:
- **Portal publik** — dapat diakses tanpa login, dioptimalkan untuk SEO & koneksi lambat.
- **Panel administrasi** — di balik autentikasi, berisi seluruh modul operasional.
- **Portal warga** — area terbatas untuk layanan mandiri.

### 2.2 Karakteristik Pengguna
| Kelompok | Literasi digital | Perangkat dominan | Implikasi desain |
|----------|------------------|-------------------|------------------|
| Perangkat desa | Rendah–sedang | Laptop + ponsel | Alur linier, label bahasa Indonesia, konfirmasi jelas, *undo* |
| Kadus/RT/RW | Rendah | Ponsel Android kelas bawah | *Mobile-first*, ukuran unduhan kecil, *offline-tolerant* |
| Warga | Beragam | Ponsel | Bantuan kontekstual, minim istilah teknis, bisa tanpa email |
| Pengawas | Sedang | Laptop | Fokus laporan & ekspor |

### 2.3 Lingkungan Operasi
- Peramban: Chrome/Edge/Firefox 2 versi terakhir, Safari iOS 15+, Chrome Android 100+.
- Dukungan **PWA**: dapat dipasang di layar utama, *shell* berfungsi saat jaringan buruk.
- Resolusi minimum 320 px.

### 2.4 Batasan Perancangan
- Antarmuka **wajib** berbahasa Indonesia (opsi bahasa daerah = `C`).
- Semua halaman utama harus dapat digunakan pada jaringan 3G (lihat NFR-PERF-04).
- Tidak boleh ada ketergantungan pada layanan berbayar pihak ketiga pada MVP, kecuali
  pengiriman email/WhatsApp.

---

## 3. Daftar Use Case

### 3.1 Diagram konteks (tekstual)

```
                        ┌─────────────────────────────┐
   Warga / Publik ─────►│                             │◄──── Pengawas (BPD/Camat)
   Perangkat Desa ─────►│   Sistem Informasi Desa     │
   Kadus / RT ─────────►│                             │
                        └──────────┬──────────────────┘
                                   │
          ┌────────────────────────┼─────────────────────────┐
          ▼                        ▼                         ▼
   Layanan Email/WA        Penyimpanan Objek        (Opsional) Web Service
   (notifikasi)            (berkas & arsip)          Dukcapil / SISKEUDES
```

### 3.2 Indeks Use Case

| ID | Use Case | Aktor utama | Prioritas |
|----|----------|-------------|-----------|
| UC-01 | Mengelola data penduduk | OP, AD | M |
| UC-02 | Mengelola kartu keluarga | OP, AD | M |
| UC-03 | Mencatat mutasi penduduk | OP, KK | M |
| UC-04 | Impor data penduduk massal | AD | M |
| UC-05 | Mengajukan surat (daring, oleh warga) | WG | M |
| UC-06 | Melayani surat *walk-in* | OP | M |
| UC-07 | Memverifikasi pengajuan surat | KK | M |
| UC-08 | Menyetujui & menerbitkan surat | KD, AD | M |
| UC-09 | Memverifikasi keaslian surat via QR | PB | M |
| UC-10 | Mengelola template & jenis surat | AD | M |
| UC-11 | Mengelola konten portal (berita/pengumuman/agenda) | AD, OP | M |
| UC-12 | Melihat statistik & profil desa | PB | M |
| UC-13 | Menyampaikan pengaduan | WG, PB | M |
| UC-14 | Menindaklanjuti pengaduan | KK, AD | M |
| UC-15 | Registrasi & verifikasi akun warga | WG, AD | M |
| UC-16 | Mengelola pengguna & peran | AD | M |
| UC-17 | Melihat dashboard eksekutif | KD, PW | M |
| UC-18 | Mencetak laporan & buku administrasi | AD, KK | M |
| UC-19 | Meninjau audit log | AD | M |
| UC-20 | Mempublikasikan APBDes & realisasi | KK(Keu), AD | S |
| UC-21 | Mengelola aset desa | KK, AD | S |
| UC-22 | Mengelola data penerima bantuan | KK(Kesra) | S |
| UC-23 | Mengusulkan koreksi data dari lapangan | KS | S |
| UC-24 | Mengelola identitas desa, wilayah & perangkat | AD | M |

### 3.3 Use Case Rinci (yang kompleks)

#### UC-05 — Mengajukan surat (daring)
| Aspek | Keterangan |
|-------|-----------|
| **Aktor** | Warga terdaftar (WG) |
| **Prakondisi** | Akun warga **terverifikasi** dan tertaut ke satu record penduduk aktif |
| **Pemicu** | Warga memilih menu *Ajukan Surat* |
| **Hasil akhir** | Pengajuan tersimpan status `DIAJUKAN`, nomor tiket terbit, notifikasi terkirim ke verifikator |

**Alur utama**
1. Warga membuka daftar jenis surat; sistem menampilkan nama layanan, syarat, biaya, dan estimasi waktu.
2. Warga memilih satu jenis surat.
3. Sistem menampilkan formulir: **data identitas terisi otomatis & terkunci (read-only)**;
   hanya *field* khusus layanan yang dapat diisi (mis. tujuan penggunaan surat).
4. Warga dapat memilih pemohon = diri sendiri atau anggota KK yang sama (RULE-11).
5. Warga mengunggah lampiran syarat (JPG/PNG/PDF, maks 5 MB/berkas).
6. Warga meninjau ringkasan dan mengirim.
7. Sistem memvalidasi (FR-SRT-03), menyimpan, menerbitkan **nomor tiket**
   (`SRT-{tahun}-{urut}`), mencatat audit log, mengirim notifikasi.
8. Sistem menampilkan halaman konfirmasi berisi nomor tiket & estimasi selesai.

**Alur alternatif / pengecualian**
- **5a** Berkas > 5 MB atau format salah → pesan spesifik, berkas lain tidak hilang.
- **7a** Validasi gagal → formulir kembali dengan **isi tetap terjaga** dan penanda
  galat per *field*.
- **7b** Ada pengajuan aktif dengan jenis surat sama → sistem memperingatkan dan meminta
  konfirmasi, tidak memblokir.
- **7c** Data penduduk pemohon berstatus Meninggal/Pindah (RULE-03) → ditolak dengan
  penjelasan dan arahan menghubungi kantor desa.
- **x** Koneksi terputus saat mengirim → *draft* tersimpan lokal, dapat dilanjutkan.

#### UC-07/UC-08 — Verifikasi & Penerbitan Surat
**Mesin status pengajuan surat:**

```
  DRAFT ──kirim──► DIAJUKAN ──verifikasi──► DIVERIFIKASI ──setujui──► DITERBITKAN
    │                  │                          │                        │
    │                  ├──revisi──► PERLU_REVISI ──┘                        ├─ batal ─► DIBATALKAN
    │                  │              │  (warga perbaiki → DIAJUKAN)        │
    └──hapus──► (hilang)├──tolak────► DITOLAK (final)                       └─ (arsip otomatis 5 th)
```

**Aturan transisi (wajib ditegakkan di sisi server, bukan hanya UI):**

| Dari | Ke | Peran berwenang | Prasyarat |
|------|----|-----------------|-----------|
| DRAFT | DIAJUKAN | WG, OP | Formulir & syarat lengkap |
| DIAJUKAN | DIVERIFIKASI | KK, AD | Pemeriksa ≠ pengaju (RULE-06) |
| DIAJUKAN | PERLU_REVISI | KK, AD | Catatan revisi wajib diisi |
| DIAJUKAN | DITOLAK | KK, AD | Alasan penolakan wajib diisi |
| DIVERIFIKASI | DITERBITKAN | KD, AD (sesuai konfigurasi jenis surat) | **Nomor surat terbit di sini** (RULE-04) |
| DITERBITKAN | DIBATALKAN | AD | Alasan wajib; surat lama tetap terarsip (RULE-05) |
| PERLU_REVISI | DIAJUKAN | WG, OP | — |

Transisi lain **harus** ditolak dengan HTTP 409 dan tidak mengubah data.

#### UC-04 — Impor data penduduk massal
1. AD mengunduh **template** Excel resmi (kolom & kode referensi sudah ditetapkan).
2. AD mengunggah berkas (maks 10 MB / 20.000 baris per unggahan).
3. Sistem memproses **asinkron** (*queue*) dan menampilkan progres.
4. Sistem menghasilkan **laporan pra-validasi**: baris valid, baris galat beserta
   nomor baris, nama kolom, dan sebab galat.
5. AD meninjau, lalu memilih: *Impor hanya baris valid* atau *Batalkan*.
6. Impor dijalankan dalam satu transaksi logis per *batch*; data yang sudah ada
   diperbarui berdasarkan NIK (*upsert*) dengan riwayat perubahan tercatat.
7. Sistem menyajikan ringkasan: ditambah / diperbarui / dilewati, dan tautan unduh
   berkas galat untuk diperbaiki.

---

## 4. Kebutuhan Fungsional

> Format: **FR-{MODUL}-{no}** · kolom *BR* menunjukkan ketertelusuran ke BRD.

### 4.1 Autentikasi & Akun (AUT)
| ID | Kebutuhan | Prio | BR |
|----|-----------|------|-----|
| FR-AUT-01 | Login dengan email **atau** nomor ponsel + kata sandi | M | BR-40 |
| FR-AUT-02 | Kata sandi minimal 10 karakter, dicek terhadap daftar kata sandi bocor, disimpan dengan *hash* Argon2id/bcrypt | M | BR-40 |
| FR-AUT-03 | Pembatasan percobaan login: 5 gagal → jeda bertingkat; 10 gagal → akun terkunci 30 menit + notifikasi | M | BR-40 |
| FR-AUT-04 | Reset kata sandi via tautan sekali pakai (kedaluwarsa 60 menit) ke email/OTP SMS/WA | M | — |
| FR-AUT-05 | **2FA wajib** (TOTP atau OTP) untuk peran AD dan KD | M | BR-40 |
| FR-AUT-06 | Sesi kedaluwarsa setelah 30 menit tidak aktif (panel admin) / 14 hari (warga, *remember me*) | M | — |
| FR-AUT-07 | Daftar sesi aktif & tombol "keluarkan semua perangkat" | S | — |
| FR-AUT-08 | Registrasi warga: input NIK + no. KK + nama → sistem **mencocokkan** ke data penduduk; status akun `MENUNGGU_VERIFIKASI` sampai disetujui AD/OP | M | BR-10 |
| FR-AUT-09 | Verifikasi warga mendukung dua jalur: unggah foto KTP (diperiksa petugas) atau verifikasi tatap muka di kantor | M | BR-10 |
| FR-AUT-10 | Sistem **tidak** mengungkap apakah suatu NIK terdaftar saat registrasi/reset gagal (anti-enumerasi) | M | R-1 |

### 4.2 Kependudukan (PDK)
| ID | Kebutuhan | Prio | BR |
|----|-----------|------|-----|
| FR-PDK-01 | CRUD penduduk dengan atribut sesuai dokumen 05 §3.2 | M | BR-01 |
| FR-PDK-02 | Validasi NIK: 16 digit numerik, unik (RULE-01), **cek digit tanggal lahir & kode wilayah** sebagai peringatan (bukan penolakan keras) | M | BR-01 |
| FR-PDK-03 | Semua kolom berkode (agama, pendidikan, pekerjaan, status perkawinan, hubungan keluarga, golongan darah) memakai **tabel referensi**, bukan teks bebas | M | BR-01 |
| FR-PDK-04 | Pencarian penduduk: nama (parsial, toleran salah ketik), NIK, no. KK, alamat; filter dusun/RW/RT, jenis kelamin, rentang umur, status | M | BR-01 |
| FR-PDK-05 | Hasil pencarian ter-*paginate*, maks 50 baris/halaman, respons ≤ 1 s untuk 50.000 record | M | NFR-PERF |
| FR-PDK-06 | *Soft delete* + pemulihan; penghapusan permanen hanya AD dengan alasan | M | RULE-08 |
| FR-PDK-07 | Riwayat perubahan per record: *field*, nilai lama, nilai baru, pengubah, waktu | M | BR-02 |
| FR-PDK-08 | CRUD keluarga/KK; tepat satu Kepala Keluarga (RULE-02) ditegakkan sistem | M | BR-01 |
| FR-PDK-09 | Pindah anggota antar-KK dengan pencatatan alasan & riwayat | M | BR-03 |
| FR-PDK-10 | Mutasi: Kelahiran, Kematian, Pindah Datang, Pindah Keluar — masing-masing mengubah status penduduk secara otomatis & konsisten | M | BR-03 |
| FR-PDK-11 | Impor massal Excel/CSV dengan pra-validasi (UC-04) | M | BR-05 |
| FR-PDK-12 | Ekspor data penduduk (hak akses terbatas AD; **selalu** tercatat di audit log, termasuk jumlah baris) | M | BR-41 |
| FR-PDK-13 | **Laporan anomali data**: NIK duplikat/tidak valid, usia > 120 th atau negatif, KK tanpa kepala keluarga, KK ganda, anggota KK beda alamat, penduduk tanpa RT | S | BR-06 |
| FR-PDK-14 | Pengelolaan hierarki wilayah: Desa → Dusun → RW → RT, dengan penugasan pejabat per wilayah | M | BR-04 |
| FR-PDK-15 | Statistik agregat: piramida penduduk, komposisi pendidikan/pekerjaan/agama/perkawinan, per wilayah & periode | M | BR-21 |
| FR-PDK-16 | Usulan koreksi data oleh KS/RT → antrean persetujuan AD (alur *maker-checker*) | S | BR-07 |

### 4.3 Persuratan & Layanan (SRT)
| ID | Kebutuhan | Prio | BR |
|----|-----------|------|-----|
| FR-SRT-01 | Pengelolaan **jenis surat**: nama, kode, deskripsi, syarat, biaya, SLA hari, masa berlaku, aktif/nonaktif. Sistem dikirim dengan **katalog 22 jenis surat** (8 aktif) — dokumen 13 | M | BR-12 |
| FR-SRT-02 | **Template surat** berbasis variabel (`{{nama}}`, `{{nik}}`, `{{alamat}}`, `{{tanggal_terbit}}`, `{{nomor_surat}}`, dll.) dengan editor WYSIWYG + daftar variabel tersedia; *preview* sebelum simpan | M | BR-12 |
| FR-SRT-03 | **Formulir dinamis** per jenis surat: tipe *field* (teks, angka, tanggal, pilihan, unggah), wajib/opsional, aturan validasi — dikonfigurasi tanpa *coding* | M | BR-12 |
| FR-SRT-04 | Pengajuan daring oleh warga (UC-05) | M | BR-10 |
| FR-SRT-05 | Mode *walk-in*: petugas memilih penduduk lalu mengisi formulir atas nama warga; identitas petugas tercatat sebagai pembuat | M | BR-16 |
| FR-SRT-06 | Alur persetujuan **dapat dikonfigurasi** per jenis surat: 1 tahap (langsung AD) sampai 3 tahap (Kasi → Sekdes → Kades) | S | BR-15 |
| FR-SRT-07 | Penegakan mesin status (UC-07) di sisi server; transisi tidak sah → 409 | M | RULE-04 |
| FR-SRT-08 | **Penomoran surat otomatis**: pola dapat dikonfigurasi (mis. `{urut}/{kode_surat}/{kode_desa}/{bulan_romawi}/{tahun}`), reset per tahun, dijamin unik & tanpa celah lewat *locking* tingkat basis data | M | BR-13 |
| FR-SRT-09 | Pembuatan PDF: ukuran F4/A4, kop & logo desa, QR verifikasi, tanda tangan (gambar atau TTE), stempel; *watermark* "SALINAN" pada cetakan ulang | M | BR-14 |
| FR-SRT-10 | Halaman verifikasi publik via QR/kode: menampilkan **hanya** nomor surat, jenis, tanggal terbit, status sah/batal, dan nama pemohon ter-masking | M | BR-14, RULE-07 |
| FR-SRT-11 | Arsip surat terbit: pencarian per nomor, jenis, pemohon, periode; unduh ulang | M | BR-18 |
| FR-SRT-12 | Pembatalan surat terbit dengan alasan; status verifikasi QR berubah menjadi "DIBATALKAN" | M | RULE-05 |
| FR-SRT-13 | Pelacakan status oleh warga + riwayat waktu tiap tahap | M | BR-11 |
| FR-SRT-14 | Notifikasi perubahan status ke pemohon (in-app Fase 1; email/WA Fase 2) | M/S | BR-11 |
| FR-SRT-15 | Monitor SLA: pengajuan melewati SLA ditandai & muncul di dashboard pimpinan | S | O-2 |
| FR-SRT-16 | *Hook* integrasi TTE tersertifikasi (antarmuka disiapkan, implementasi Fase 3) | C | BR-17 |

### 4.4 Portal Publik (PRT)
| ID | Kebutuhan | Prio | BR |
|----|-----------|------|-----|
| FR-PRT-01 | Halaman profil desa: sejarah, visi-misi, geografis, demografi, struktur organisasi & foto perangkat | M | BR-20 |
| FR-PRT-02 | Berita: kategori, *tag*, gambar utama, penjadwalan terbit, status draf/terbit | M | BR-20 |
| FR-PRT-03 | Pengumuman & agenda kegiatan (dengan tanggal & lokasi) | M | BR-20 |
| FR-PRT-04 | Halaman **standar pelayanan**: daftar jenis surat, syarat, biaya, waktu penyelesaian | M | BR-23 |
| FR-PRT-05 | Halaman statistik interaktif dari data agregat; **penyamaran sel < 5 orang** | M | BR-21 |
| FR-PRT-06 | Galeri foto/video kegiatan | S | BR-20 |
| FR-PRT-07 | Unduhan dokumen publik (Perdes, laporan, APBDes) | S | BR-22 |
| FR-PRT-08 | SEO: *meta tag*, Open Graph, sitemap.xml, data terstruktur `GovernmentOrganization` | M | BR-24 |
| FR-PRT-09 | Pencarian isi portal | S | — |
| FR-PRT-10 | Halaman transparansi APBDes: ringkasan pendapatan/belanja/pembiayaan, grafik, realisasi vs pagu, unduh | S | BR-22 |

### 4.5 Pengaduan (PGD)
| ID | Kebutuhan | Prio | BR |
|----|-----------|------|-----|
| FR-PGD-01 | Formulir pengaduan: kategori, judul, isi, lokasi (opsional titik koordinat), lampiran foto | M | BR-30 |
| FR-PGD-02 | Opsi **anonim**: identitas tidak disimpan; kode pelacakan tetap diberikan | S | BR-31, RULE-09 |
| FR-PGD-03 | Mesin status: `BARU → DITERIMA → DIPROSES → SELESAI` / `DITOLAK` dengan catatan wajib pada penolakan | M | BR-30 |
| FR-PGD-04 | Penugasan ke pengguna/bidang; notifikasi ke penerima tugas | M | BR-30 |
| FR-PGD-05 | Tindak lanjut dengan narasi + bukti foto; terlihat oleh pelapor | M | BR-30 |
| FR-PGD-06 | SLA & eskalasi otomatis bila melewati batas (triase 2 hari, selesai 14 hari kerja) | S | BR-30 |
| FR-PGD-07 | Ringkasan pengaduan selesai dapat dipublikasikan ke portal (opt-in per kasus) | C | BR-30 |
| FR-PGD-08 | Penyaringan konten: batas panjang, deteksi spam sederhana, *rate limit* per IP/akun | M | — |

### 4.6 Dashboard & Laporan (LAP)
| ID | Kebutuhan | Prio | BR |
|----|-----------|------|-----|
| FR-LAP-01 | Dashboard pimpinan: total penduduk, KK, perubahan bulan ini, layanan masuk/selesai/terlambat, pengaduan terbuka, tren 12 bulan | M | BR-45 |
| FR-LAP-02 | Dashboard operasional: antrean tugas pengguna aktif | M | — |
| FR-LAP-03 | Laporan rekap penduduk per wilayah & kategori | M | BR-45 |
| FR-LAP-04 | Laporan mutasi penduduk per periode | M | BR-45 |
| FR-LAP-05 | Laporan layanan surat: volume per jenis, rata-rata waktu penyelesaian, persentase tepat SLA | M | BR-18 |
| FR-LAP-06 | Cetak **Buku Induk Penduduk** & **Buku Mutasi** sesuai format Permendagri 47/2016 | S | BR-42 |
| FR-LAP-07 | Semua laporan dapat diekspor **Excel** & **PDF**; ekspor besar diproses asinkron dengan notifikasi tautan unduh | M | BR-45 |
| FR-LAP-08 | Buku administrasi desa digital lainnya (Peraturan Desa, Keputusan Kades, Inventaris, Aparat, Tanah Kas, Agenda, Ekspedisi, Lembaran Desa) | S | BR-42 |

### 4.7 Modul Fase 2 (ringkas — akan dirinci pada SRS v2.0)
| ID | Kebutuhan | Prio |
|----|-----------|------|
| FR-KEU-01..08 | Entri/impor rekap APBDes (pendapatan, belanja per bidang/kegiatan, pembiayaan), status draf→publikasi, grafik realisasi, unduh dokumen | S |
| FR-AST-01..06 | Inventaris aset: kategori, perolehan, nilai, kondisi, lokasi, penyusutan sederhana, label/kode aset, laporan mutasi aset | S |
| FR-BSS-01..06 | Data program bantuan, penerima, verifikasi berlapis RT→Kadus→Kasi, deteksi penerima ganda antar-program, laporan | S |
| FR-NTF-01..04 | Notifikasi email & WhatsApp (templat, antrean, percobaan ulang, log pengiriman) | S |

### 4.8 Administrasi Sistem (ADM)
| ID | Kebutuhan | Prio |
|----|-----------|------|
| FR-ADM-01 | CRUD pengguna; satu pengguna dapat memiliki beberapa peran | M |
| FR-ADM-02 | Peran & izin berbasis *permission* granular (`penduduk.lihat`, `surat.setujui`, …) | M |
| FR-ADM-03 | Penonaktifan pengguna (tanpa menghapus) dan pencabutan sesi seketika | M |
| FR-ADM-04 | **Audit log** *append-only*: aktor, aksi, entitas, id, nilai sebelum/sesudah (data sensitif ter-*hash*/masking), IP, *user agent*, waktu | M |
| FR-ADM-05 | Pencarian & ekspor audit log (akses terbatas AD/SA) | M |
| FR-ADM-06 | Pengaturan desa: identitas, logo, kop surat, **pola nomor surat**, SLA, pejabat penanda tangan (lihat 13 §3–§4) | M |
| FR-ADM-07 | Pengelolaan perangkat desa (`officials`): struktur organisasi untuk portal + daftar pejabat berwenang menandatangani surat | M |
| FR-ADM-08 | Mode pemeliharaan dengan halaman informatif | S |
| FR-ADM-09 | Pengelolaan tabel referensi (agama, pendidikan, pekerjaan, …) | M |
| FR-ADM-10 | Pencadangan terjadwal + uji pemulihan terdokumentasi | M |

### 4.9 Matriks Hak Akses (RBAC)

`C` Create · `R` Read · `U` Update · `D` Delete · `A` Approve · `X` Export · `—` tidak ada akses
`R*` = hanya data milik sendiri/keluarga · `R⁻` = agregat/ter-masking

| Sumber daya | AD | KD | KK | KS | OP | WG | PW | PB |
|-------------|----|----|----|----|----|----|----|----|
| Identitas desa & pengaturan | R,U | R | — | — | — | — | — | — |
| Perangkat desa (`officials`) | CRUD | R | — | — | — | — | R | R⁻ |
| Pengguna & Peran | CRUD | R | — | — | — | — | — | — |
| Penduduk | CRUD,X | R,X | R,U | R(wilayah) | C,R,U | R* | R⁻,X | — |
| Kartu Keluarga | CRUD,X | R | R,U | R(wilayah) | C,R,U | R* | R⁻ | — |
| Mutasi penduduk | CRUD | R | C,R,U | R | C,R | — | R⁻ | — |
| Wilayah (dusun/RW/RT) | CRUD | R | R | R | R | — | R | R |
| Jenis surat & template | CRUD | R | R | — | R | R⁻ | R | R⁻ |
| Pengajuan surat | CRUD,A | R,A | R,U,A | R(wilayah) | C,R,U | C,R* | R⁻ | — |
| Surat terbit (arsip) | R,U(batal),X | R | R | — | R | R* | R⁻,X | — |
| Verifikasi QR | R | R | R | R | R | R | R | R⁻ |
| Konten portal | CRUD | R,A | C,R,U | — | C,R,U | — | R | R |
| Pengaduan | CRUD,A | R | R,U,A | R(wilayah) | R | C,R* | R⁻ | C(anonim) |
| Keuangan/APBDes | R,U,A,X | R,A | CRUD(Keu) | — | — | — | R,X | R⁻ |
| Aset desa | CRUD,X | R | CRUD(bidang) | R | R | — | R,X | — |
| Bantuan sosial | CRUD,X | R | CRUD(Kesra) | R,U(usul) | R | R* | R⁻,X | — |
| Laporan & ekspor | R,X | R,X | R,X | R(wilayah) | R | — | R,X | — |
| Audit log | R,X | R | — | — | — | — | R | — |

**Aturan tambahan yang wajib diuji:**
1. KS hanya melihat data **wilayah yang ditugaskan** (penegakan di lapis *repository*,
   bukan UI — lihat 04 §4.1).
2. WG hanya mengakses data dirinya & anggota satu KK (RULE-11).
3. Setiap `X` (ekspor) **selalu** tercatat di audit log beserta jumlah baris & filter.
4. Objek di luar lingkup pengguna (wilayah lain / keluarga lain) **selalu** 404
   (bukan 403), agar keberadaan data tidak terbocorkan.

> **⚠ Catatan khusus MySQL (KEP-002):** butir 1 dan 2 **tidak** punya jaring pengaman di
> tingkat basis data. Keduanya sepenuhnya bergantung pada kode. Karena itu lapis uji
> otorisasi (09 §4.3) bersifat **wajib** dan tidak boleh di-*skip* di CI.

---

## 5. Kebutuhan Non-Fungsional

### 5.1 Kinerja (PERF)
| ID | Kebutuhan | Target |
|----|-----------|--------|
| NFR-PERF-01 | Waktu respons API baca (p95) | ≤ 500 ms |
| NFR-PERF-02 | Waktu respons API tulis (p95) | ≤ 1.000 ms |
| NFR-PERF-03 | Pemuatan halaman portal publik (LCP, 4G) | ≤ 2,5 s |
| NFR-PERF-04 | Ukuran transfer halaman portal pertama kali | ≤ 500 KB (gzip/brotli) |
| NFR-PERF-05 | Pembuatan PDF surat | ≤ 5 s |
| NFR-PERF-06 | Pengguna bersamaan (panel admin) | 50 tanpa degradasi; uji sampai 200 |
| NFR-PERF-07 | Volume data | 20.000 penduduk, 1,5 juta baris audit log (margin 4× dari proyeksi 5 tahun di 05 §8) |
| NFR-PERF-08 | Ekspor > 5.000 baris | Diproses asinkron, bukan permintaan sinkron |

### 5.2 Keamanan (SEC) — rincian di dokumen 08
| ID | Kebutuhan |
|----|-----------|
| NFR-SEC-01 | HTTPS wajib (TLS 1.2+), HSTS aktif, HTTP dialihkan |
| NFR-SEC-02 | Otorisasi diperiksa di sisi server pada **setiap** permintaan (tidak mengandalkan UI) |
| NFR-SEC-03 | Bebas dari OWASP Top 10; wajib proteksi IDOR, SQL injection, XSS, CSRF, SSRF |
| NFR-SEC-04 | Data pribadi terenkripsi saat transit & saat diam (enkripsi tingkat kolom untuk NIK & no. KK) |
| NFR-SEC-05 | Berkas unggahan: validasi tipe nyata (*magic bytes*), penamaan ulang, disimpan di luar *web root*, pemindaian malware |
| NFR-SEC-06 | *Rate limiting* pada autentikasi, pengajuan, dan pengaduan |
| NFR-SEC-07 | *Security headers*: CSP, X-Content-Type-Options, Referrer-Policy, Permissions-Policy |
| NFR-SEC-08 | Tidak ada kredensial/kunci di dalam kode sumber (*secret manager*/variabel lingkungan) |
| NFR-SEC-09 | Pemindaian dependensi otomatis di CI; kerentanan Critical/High menggagalkan *build* |

### 5.3 Ketersediaan & Keandalan (REL)
| ID | Kebutuhan | Target |
|----|-----------|--------|
| NFR-REL-01 | *Uptime* bulanan | ≥ 99,5% (jam kerja ≥ 99,9%) |
| NFR-REL-02 | **RPO** (toleransi kehilangan data) | ≤ 24 jam (cadangan harian) · ≤ 1 jam untuk basis data transaksi (WAL/binlog) |
| NFR-REL-03 | **RTO** (waktu pulih) | ≤ 4 jam |
| NFR-REL-04 | Retensi cadangan | Harian 7 · Mingguan 4 · Bulanan 12 |
| NFR-REL-05 | Uji pemulihan cadangan | Minimal 1× per kuartal, terdokumentasi |
| NFR-REL-06 | Cadangan disimpan di lokasi/penyedia berbeda dari server produksi | Wajib |

### 5.4 Kebergunaan & Aksesibilitas (USA)
| ID | Kebutuhan |
|----|-----------|
| NFR-USA-01 | Semua antarmuka berbahasa Indonesia, istilah selaras dengan praktik desa |
| NFR-USA-02 | *Responsive* 320 px–1920 px; tabel panjang punya versi kartu di layar kecil |
| NFR-USA-03 | WCAG 2.1 level **AA**: kontras ≥ 4.5:1, navigasi penuh dengan papan tombol (keyboard), label *form* eksplisit, fokus terlihat |
| NFR-USA-04 | Pesan galat menyebutkan **apa yang salah dan apa yang harus dilakukan**, bukan kode teknis |
| NFR-USA-05 | Aksi destruktif memerlukan konfirmasi; ada *undo* untuk *soft delete* |
| NFR-USA-06 | Tugas umum (buat surat *walk-in*) selesai ≤ 10 interaksi |
| NFR-USA-07 | Bantuan kontekstual & panduan langkah pertama (*onboarding*) per peran |

### 5.5 Pemeliharaan & Kualitas Kode (MNT)
| ID | Kebutuhan |
|----|-----------|
| NFR-MNT-01 | Cakupan uji otomatis: ≥ 70% keseluruhan, ≥ 90% pada modul otorisasi, penomoran surat, dan mutasi penduduk |
| NFR-MNT-02 | *Linter* + *formatter* + analisis statis wajib lulus di CI |
| NFR-MNT-03 | Migrasi basis data berversi, dapat dijalankan maju; setiap migrasi punya rencana mundur |
| NFR-MNT-04 | Dokumentasi API otomatis (OpenAPI) selalu sinkron dengan kode |
| NFR-MNT-05 | Semua aturan bisnis yang berubah-ubah (pola nomor, SLA, template) **wajib** berupa konfigurasi, bukan *hardcode* |
| NFR-MNT-06 | *Logging* terstruktur (JSON) + *correlation id* per permintaan |

### 5.6 Kepatuhan & Portabilitas (CMP)
| ID | Kebutuhan |
|----|-----------|
| NFR-CMP-01 | Memenuhi kewajiban pengendali data pribadi menurut UU 27/2022 (dasar pemrosesan, hak subjek data, pemberitahuan insiden ≤ 3×24 jam) |
| NFR-CMP-02 | Data warga **wajib** disimpan di pusat data wilayah Indonesia |
| NFR-CMP-03 | Kode referensi mengikuti standar nasional (kode wilayah Kemendagri, kode Dukcapil) |
| NFR-CMP-04 | Tersedia ekspor data lengkap dalam format terbuka (CSV/JSON) agar desa tidak terkunci vendor |
| NFR-CMP-05 | Dapat dipasang di infrastruktur kabupaten (*on-premise*) maupun awan |

---

## 6. Antarmuka Eksternal

| Sistem | Arah | Mekanisme | Fase | Catatan |
|--------|------|-----------|------|---------|
| Layanan email (SMTP) | keluar | SMTP/API | 1 | Notifikasi & reset sandi |
| Gateway WhatsApp | keluar | HTTP API | 2 | Perlu nomor bisnis & biaya per pesan |
| Penyimpanan objek (S3-kompatibel) | dua arah | S3 API | 1 | Berkas unggahan & arsip PDF |
| **Dukcapil (web service NIK)** | masuk | HTTPS + perjanjian kerja sama | 3 | **Opsional**; memerlukan PKS resmi. Sistem harus tetap berfungsi penuh tanpa ini |
| SISKEUDES | masuk | Impor berkas (CSV/Excel) | 2 | Tidak ada integrasi langsung; hindari ketergantungan |
| SIPD / Prodeskel | keluar | Ekspor berkas | 2 | Format ekspor menyesuaikan permintaan |
| Peta dasar (OSM) | masuk | Tile HTTP | 3 | Untuk modul GIS |

> **ASUMSI:** Tidak ada integrasi langsung wajib pada MVP. Bila integrasi Dukcapil
> ditetapkan wajib, tambahkan ±3 sprint dan jalur legal PKS (lihat dokumen 11).

---

## 7. Matriks Ketertelusuran (Ringkas)

| BR | FR terkait | UC | Uji (dok. 09) |
|----|-----------|----|----|
| BR-01 | FR-PDK-01..08 | UC-01, UC-02 | TC-PDK-* |
| BR-02 | FR-PDK-07, FR-ADM-04 | UC-19 | TC-ADM-04 |
| BR-03 | FR-PDK-10 | UC-03 | TC-PDK-10 |
| BR-05 | FR-PDK-11 | UC-04 | TC-PDK-11 |
| BR-10 | FR-SRT-04, FR-AUT-08 | UC-05, UC-15 | TC-SRT-04 |
| BR-11 | FR-SRT-13, FR-SRT-14 | UC-05 | TC-SRT-13 |
| BR-12 | FR-SRT-01..03 | UC-10 | TC-SRT-02 |
| BR-13 | FR-SRT-08 | UC-08 | TC-SRT-08 (termasuk uji konkurensi) |
| BR-14 | FR-SRT-09, FR-SRT-10 | UC-09 | TC-SRT-10 |
| BR-21 | FR-PDK-15, FR-PRT-05 | UC-12 | TC-PRT-05 |
| BR-30 | FR-PGD-01..05 | UC-13, UC-14 | TC-PGD-* |
| BR-40 | FR-AUT-*, FR-ADM-02 | UC-16 | TC-SEC-RBAC-* |
| BR-41 | FR-ADM-04, FR-ADM-05 | UC-19 | TC-ADM-04 |
| BR-45 | FR-LAP-03..07 | UC-18 | TC-LAP-* |
| BR-46 | FR-ADM-10, NFR-REL-* | — | TC-OPS-BACKUP |
