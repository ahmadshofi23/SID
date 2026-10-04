# 09 — Strategi Pengujian (QA Plan)
**Proyek:** Sistem Informasi Desa (SID) · **Versi:** 2.0 · **Lingkup:** satu desa · **DB:** MySQL 8

---

## 1. Tujuan & Prinsip

1. **Yang paling berisiko diuji paling ketat.** Tiga area dengan dampak terbesar:
   **otorisasi**, **penomoran surat**, dan **integritas data penduduk**.
2. **Uji bersamaan dengan kode**, bukan setelahnya. Pekerjaan tanpa uji tidak
   dianggap selesai (lihat §8 Definition of Done).
3. **Uji perilaku, bukan implementasi.** Uji harus tetap lulus saat kode di-*refactor*.
4. **Setiap bug yang lolos ke produksi melahirkan satu uji regresi.** Tanpa kecuali.

### Piramida pengujian (target)
```
        ┌──────────────┐
        │   E2E  ~5%   │  alur kritis saja (±25 skenario)
        ├──────────────┤
        │ Integrasi 25%│  endpoint API + basis data nyata
        ├──────────────┤
        │  Unit   70%  │  aturan bisnis, kalkulasi, validasi
        └──────────────┘
      + Lapis khusus: UJI OTORISASI (matriks penuh) & UJI KEAMANAN
```

---

## 2. Cakupan & Target Kualitas

| Metrik | Target | Penegakan |
|--------|--------|-----------|
| Cakupan baris keseluruhan | ≥ 70% | CI gagal di bawah ambang |
| Cakupan modul otorisasi & lapis *repository* | ≥ 95% | CI gagal |
| Cakupan penomoran surat | 100% cabang | CI gagal |
| Cakupan mutasi penduduk | ≥ 90% | CI gagal |
| Uji gagal | 0 | CI gagal |
| Kerentanan Critical/High | 0 | CI gagal |
| Galat *linter*/analisis statis | 0 | CI gagal |
| *Flaky test* | 0 toleransi | Uji *flaky* langsung diperbaiki atau dikarantina dengan tiket |

---

## 3. Lingkungan & Data Uji

| Lingkungan | Kapan | Data |
|-----------|-------|------|
| Lokal | Pengembangan harian | *Seed* sintetis: 500 penduduk, 150 KK, katalog 22 jenis surat (dok. 13) |
| CI | Setiap *push* | Basis data sekali pakai per *job*; *factory* per uji |
| Staging | UAT & pelatihan | **Anonim**, 5.000 penduduk, data historis 1 tahun |
| Produksi | — | Hanya *smoke test* non-destruktif setelah rilis |

**Pembuatan data uji sintetis — persyaratan:**
- NIK sintetis **valid secara struktur** (kode wilayah + tanggal lahir + urut) tetapi
  **tidak merujuk** orang nyata.
- Mencakup kasus tepi: KK beranggota 1 orang, KK beranggota 12 orang, penduduk > 100 tahun,
  bayi usia 0 hari, nama dengan tanda petik (`Ma'ruf`), nama satu kata (`Suprapto`),
  nama sangat panjang (> 100 karakter), penduduk tanpa RT, penduduk meninggal, pindah keluar.
- **Dilarang** memakai data warga asli di lingkungan mana pun selain produksi.

---

## 4. Rencana Uji per Lapis

### 4.1 Uji Unit (fokus aturan bisnis)
| Area | Contoh kasus uji |
|------|------------------|
| Validasi NIK | 16 digit, bukan angka, kosong, duplikat aktif, duplikat terhapus (boleh), NIK dengan tanggal tidak valid |
| Pemformatan nomor surat | Semua *placeholder* (`{urut}`, `{kode}`, `{bulan_romawi}`, `{tahun}`), bulan Romawi I–XII, *padding* nol, pola kustom, *placeholder* tidak dikenal |
| Perhitungan umur | Hari ulang tahun, 29 Februari, tanggal lahir hari ini, kelompok umur di batas (4/5, 14/15, 64/65) |
| Hari kerja untuk SLA | Melewati akhir pekan, hari libur nasional, pengajuan hari Jumat sore |
| Mesin status | Setiap transisi sah; **semua** transisi tidak sah ditolak |
| Validasi formulir dinamis | *Field* wajib, tipe, panjang, pola, *field* tak dikenal diabaikan/ditolak |
| Masking | NIK, telepon, email; input pendek; input kosong; input null |
| Aturan kepala keluarga | Tambah kepala kedua ditolak; pindahkan kepala keluarga; KK tanpa kepala |

### 4.2 Uji Integrasi (API + basis data nyata)
| Area | Kasus uji penting |
|------|-------------------|
| CRUD penduduk | Buat → baca → ubah → hapus → pulihkan; riwayat perubahan terekam benar |
| Impor massal | Berkas valid, berkas dengan 10% baris galat, berkas kosong, kolom hilang, kolom berlebih, 20.000 baris, berkas rusak, file bukan Excel dengan ekstensi `.xlsx` |
| Mutasi | Kematian → status berubah → keluar dari statistik → tidak bisa ajukan surat |
| Alur surat | Alur lengkap 1/2/3 tahap; penolakan; revisi lalu ajukan ulang; pembatalan |
| Penomoran | Lihat TC-SRT-08 di §5 |
| Berkas | Unggah valid, melebihi ukuran, tipe salah, ekstensi dipalsukan, nama berkas berbahaya (`../../etc/passwd`) |
| Pekerjaan asinkron | Pekerjaan berhasil, gagal, dicoba ulang, *timeout*, *polling* status |
| Transaksi | Kegagalan di tengah penerbitan surat **tidak** meninggalkan surat tanpa nomor atau nomor tanpa surat |

### 4.3 Uji Otorisasi (**lapis wajib, tidak boleh dilewati**)

Dibangkitkan secara terprogram: **setiap peran × setiap endpoint × setiap kepemilikan objek**.

> **Mengapa lapis ini wajib di proyek ini:** dengan MySQL (KEP-002) **tidak ada
> Row-Level Security**. Pembatasan wilayah (Kepala Dusun) dan keluarga (Warga)
> sepenuhnya ditegakkan kode. Uji ini adalah satu-satunya verifikasi otomatis bahwa
> pembatasan itu bekerja — kegagalannya **memblokir rilis**.

```
untuk setiap peran in [AD, KD, KK, KS, OP, WG, PW, anonim]:
  untuk setiap endpoint in daftar_endpoint:
    untuk setiap skenario in [objek_sendiri, objek_wilayah_sendiri,
                              objek_wilayah_lain, objek_keluarga_lain,
                              objek_tidak_ada]:
        jalankan dan bandingkan dengan matriks RBAC (SRS §4.9)
```
**Harapan yang wajib terpenuhi:**
| Skenario | Hasil wajib |
|----------|-------------|
| Kadus mengakses penduduk dusun lain | **404** (bukan 403, bukan 200) |
| Warga mengakses penduduk KK lain | 404 |
| Operator mencoba menghapus penduduk | 403 |
| Operator menyetujui surat yang dibuatnya | 422 `SELF_APPROVAL_FORBIDDEN` |
| Anonim mengakses endpoint admin apa pun | 401 |
| Pengguna mengirim `resident_id`/`status` di *body* saat PATCH | Diabaikan, tidak berpengaruh |
| Pengguna mengirim `role_id` saat memperbarui profilnya | Diabaikan |
| Token dicabut lalu dipakai | 401 seketika |
| Pengawas mencoba POST/PATCH apa pun | 403 |

### 4.4 Uji End-to-End (alur kritis, dijalankan di CI malam & sebelum rilis)
| ID | Skenario |
|----|----------|
| E2E-01 | Warga daftar → diverifikasi petugas → login → ajukan SKTM → diverifikasi → disetujui → unduh PDF → pindai QR terverifikasi sah |
| E2E-02 | Operator layani *walk-in* → Kasi verifikasi → Kades setujui → cetak |
| E2E-03 | Pengajuan ditolak dengan alasan → warga melihat alasan → ajukan ulang |
| E2E-04 | Admin impor 1.000 penduduk dari Excel → statistik portal publik ikut berubah |
| E2E-05 | Catat kematian → penduduk hilang dari statistik → tidak dapat diajukan surat |
| E2E-06 | Warga lapor pengaduan → ditugaskan → ditindaklanjuti → selesai → pelapor melihat hasil |
| E2E-07 | Pengaduan anonim → lacak dengan kode → identitas tidak tersimpan di mana pun |
| E2E-08 | Admin buat jenis surat baru + template + formulir → warga mengajukannya → PDF benar |
| E2E-11 | **Katalog bawaan:** untuk 8 surat aktif (dok. 13 §7), terbitkan satu surat uji masing-masing → PDF terbentuk, semua `{{variabel}}` terisi (tidak ada `{{` tersisa di PDF), nomor sesuai pola, QR dapat diverifikasi |
| E2E-12 | SKL disetujui → tawaran catat mutasi Kelahiran muncul → diterima → anak masuk KK & statistik bertambah (dok. 13 §5.4) |
| E2E-13 | SKM disetujui → tawaran catat mutasi Kematian → status `meninggal` → hilang dari statistik → tidak dapat mengajukan surat (dok. 13 §5.5) |
| E2E-09 | Surat dibatalkan → verifikasi QR menampilkan "DIBATALKAN" |
| E2E-10 | Kadus dari ponsel mengusulkan koreksi data → disetujui Admin → data berubah + riwayat tercatat |

Dijalankan pada: Chrome desktop, Chrome Android (375 px), Safari iOS.

### 4.5 Uji Non-Fungsional
| Jenis | Metode | Kriteria lulus |
|-------|--------|----------------|
| Beban | 50 pengguna bersamaan, 30 menit (k6/JMeter) | p95 baca ≤ 500 ms, p95 tulis ≤ 1 s, galat < 0,1% |
| Tekanan | Naikkan sampai gagal | Degradasi bertahap, bukan macet total; pulih otomatis |
| Volume | 20.000 penduduk, 1,5 juta audit log | Pencarian & laporan tetap memenuhi NFR-PERF; `LIKE '%…%'` pada nama tetap ≤ 200 ms |
| Konkurensi | 20 penerbitan surat bersamaan | **0 nomor ganda, 0 nomor terlewat** |
| Kinerja frontend | Lighthouse (4G *throttled*) | Performance ≥ 85, Accessibility ≥ 95 pada portal |
| Aksesibilitas | axe-core otomatis + tinjauan manual papan tombol & pembaca layar | 0 pelanggaran serius; alur kritis dapat diselesaikan hanya dengan papan tombol |
| Keamanan | SAST, pemindaian dependensi, DAST (OWASP ZAP), uji penetrasi pihak ketiga | 0 Critical/High terbuka |
| Pemulihan bencana | Pulihkan dari cadangan di lingkungan bersih | RTO ≤ 4 jam, RPO ≤ 24 jam, data terverifikasi utuh |
| Kompatibilitas | Chrome/Edge/Firefox 2 versi terakhir, Safari iOS 15+, Android 100+ | Tidak ada kerusakan fungsi |
| Lokalisasi | Format tanggal Indonesia, pemisah ribuan, zona waktu WIB/WITA/WIT | Benar di semua tampilan & ekspor |

---

## 5. Kasus Uji Prioritas (Rinci)

### TC-SRT-08 — Penomoran surat bebas duplikat & bebas celah ⚠ **KRITIS**
| Aspek | Keterangan |
|-------|-----------|
| Terkait | FR-SRT-08, BR-13, ADR-001 |
| Prakondisi | Satu jenis surat, 20 pengajuan berstatus `diverifikasi` |
| Langkah | Jalankan 20 permintaan `approve` secara **benar-benar bersamaan** (paralel) |
| Harapan | 20 surat terbit · 20 nomor **unik** · urut 1–20 **tanpa celah** · tidak ada galat 500 |
| Varian | (a) 5 permintaan gagal di tengah (dipaksa *rollback*) → nomor **tidak** terbuang; (b) **penghitung tahun baru BELUM ADA, 10 permintaan bersamaan** → wajib tetap unik (menguji jebakan `SELECT … FOR UPDATE` pada baris tak ada di MySQL — ADR-001); (c) dua jenis surat berbeda → penghitung terpisah; (d) pola nomor tunggal (dok. 13 §3C) → satu deret untuk semua jenis |
| Mengapa kritis | Nomor ganda/terlewat adalah **temuan audit administrasi** dan merusak kepercayaan pada dokumen resmi |

### TC-SEC-SCOPE-01 — Pembatasan wilayah & keluarga ⚠ **KRITIS**
| Aspek | Keterangan |
|-------|-----------|
| Mengapa kritis | Tanpa RLS (KEP-002), ini satu-satunya pengaman terhadap kebocoran NIK antar-warga |
| Langkah A | Login sebagai **Kepala Dusun Krajan**, lalu panggil **setiap** endpoint `GET/PATCH/DELETE` dengan ID objek milik **Dusun Sidomulyo** (penduduk, keluarga, surat, pengaduan, berkas) |
| Harapan A | **Semua** 404. Data Dusun Sidomulyo tidak muncul di hasil pencarian, *autocomplete*, daftar, laporan, maupun ekspor |
| Langkah B | Login sebagai **Warga** (KK-1), lalu akses data penduduk & surat milik KK-2 |
| Harapan B | **Semua** 404, termasuk lampiran berkas dan PDF surat |
| Varian | (a) filter `region_id` dimanipulasi ke dusun lain; (b) `per_page=10000` untuk memancing kebocoran daftar; (c) ekspor dengan filter dusun lain; (d) *autocomplete* pencarian nama; (e) endpoint laporan & statistik; (f) URL PDF *pre-signed* milik KK lain |
| Pengaman tambahan | Uji analisis statis: **tidak ada** kueri ke `residents`/`families`/`letter_requests`/`complaints` dari luar lapis *repository* (04 §4.1) |

### TC-PDK-02 — Integritas data penduduk
| Langkah | Coba buat penduduk dengan NIK yang sudah dipakai penduduk aktif |
| Harapan | 422 `NIK_DUPLICATE` dengan pesan yang dapat dipahami; **tidak ada** baris baru |
| Varian | (a) NIK milik penduduk *soft-deleted* → **boleh** (menguji kolom *generated* `nik_hash_active`, 05 §2.2); (b) NIK milik penduduk status `pindah_keluar` → ditolak dengan penjelasan; (c) dua permintaan bersamaan dengan NIK sama → tepat satu berhasil, satu gagal dengan 422 (bukan 500) |
| Tambahan | **Uji RULE-02:** tambah kepala keluarga kedua ke satu KK → ditolak oleh basis data (`uq_families_single_head`), bukan hanya oleh kode |

### TC-PRT-05 — Statistik publik tidak membocorkan data pribadi
| Langkah | Akses semua endpoint `/public/*` tanpa autentikasi; coba seluruh kombinasi filter sampai sesempit mungkin |
| Harapan | Tidak ada NIK/nama/alamat lengkap; sel berisi 1–4 orang ditampilkan "< 5"; tidak ada data pribadi di *view-source*, respons API, atau *sourcemap* |
| Varian | Coba *re-identification* dengan filter sempit (satu RT + satu pekerjaan + satu kelompok umur) |

### TC-ADM-04 — Audit log tidak dapat diubah
| Langkah | Lakukan 10 aksi sensitif; coba ubah/hapus baris audit lewat API & lewat kredensial basis data aplikasi |
| Harapan | 10 baris tercatat lengkap; UPDATE/DELETE gagal pada tingkat basis data; *hash chain* terverifikasi konsisten |

### TC-OPS-BACKUP — Pemulihan cadangan
| Langkah | Ambil cadangan → buat lingkungan bersih → pulihkan → verifikasi jumlah baris, *checksum* berkas, dan beberapa surat PDF |
| Harapan | Pemulihan selesai ≤ 4 jam; semua jumlah baris cocok; PDF dapat dibuka dan *hash*-nya sama |

---

## 6. Pengujian Penerimaan Pengguna (UAT)

**Peserta:** Sekdes, Kasi Pemerintahan, Operator, 1 Kadus, 3 warga (bukan dari tim).
**Lingkungan:** Staging dengan data anonim.
**Durasi:** 5 hari kerja per fase.

**Skenario UAT ditulis sebagai tugas nyata, bukan daftar klik:**
> "Pak Ahmad datang ke kantor desa hari ini. Beliau butuh Surat Keterangan Tidak Mampu
> untuk pengajuan beasiswa anaknya. Beliau membawa fotokopi KK dan KTP. Layani beliau
> sampai surat siap ditandatangani Kepala Desa."

**Kriteria lulus UAT:**
| Kriteria | Ambang |
|----------|--------|
| Semua FR berprioritas **Must** berfungsi | 100% |
| Tugas diselesaikan peserta **tanpa bantuan** | ≥ 80% |
| Bug tingkat *Blocker*/*Critical* terbuka | 0 |
| Bug tingkat *Major* terbuka | ≤ 3, dengan rencana perbaikan bertanggal |
| Kepuasan peserta (skala 1–5) | ≥ 4,0 |

Setiap tugas yang **gagal diselesaikan** tanpa bantuan dicatat sebagai temuan
kebergunaan dengan prioritas setara bug Major — bukan sebagai "pengguna perlu dilatih".

---

## 7. Pengelolaan Cacat (Defect)

| Tingkat | Definisi | Target perbaikan |
|---------|----------|------------------|
| **Blocker** | Sistem tidak dapat dipakai; data rusak; data pribadi bocor | ≤ 4 jam |
| **Critical** | Fungsi inti gagal tanpa jalan lain; kegagalan otorisasi | ≤ 1 hari kerja |
| **Major** | Fungsi penting gagal tetapi ada jalan lain | ≤ 3 hari kerja |
| **Minor** | Gangguan kecil, salah tampilan | Sprint berikutnya |
| **Trivial** | Kosmetik, salah ketik | *Backlog* |

**Laporan bug wajib memuat:** langkah reproduksi, hasil yang diharapkan vs
yang terjadi, peran & lingkungan, `correlation_id`, tangkapan layar/rekaman.
**Dilarang** melampirkan data warga asli di tiket — gunakan data staging.

---

## 8. Definition of Done (per *user story*)

Sebuah *story* selesai bila **seluruh** poin berikut terpenuhi:
- [ ] Kriteria penerimaan terpenuhi dan diperagakan
- [ ] Uji unit & integrasi ditulis dan lulus
- [ ] Uji otorisasi ditambahkan bila *story* menyentuh data (wajib)
- [ ] *Linter*, analisis statis, dan pemindaian dependensi lulus
- [ ] Kode ditinjau dan disetujui ≥ 1 orang lain
- [ ] Pemeriksaan aksesibilitas pada layar baru lulus
- [ ] Bekerja pada lebar 375 px (diperiksa nyata, bukan diasumsikan)
- [ ] Tidak ada data sensitif di log atau di pesan galat
- [ ] Dokumentasi API diperbarui bila ada perubahan *endpoint*
- [ ] Migrasi basis data punya rencana mundur
- [ ] Teks antarmuka berbahasa Indonesia dan sudah ditinjau
- [ ] Terpasang di staging dan diverifikasi PO

---

## 9. Otomasi & Pipeline CI

| Tahap | Dijalankan | Durasi target |
|-------|-----------|---------------|
| *Linter* + format + analisis statis | Setiap *push* | < 2 menit |
| Uji unit | Setiap *push* | < 3 menit |
| Uji integrasi | Setiap *push* | < 8 menit |
| Uji otorisasi | Setiap *push* | < 5 menit |
| Pemindaian dependensi + deteksi *secret* | Setiap *push* | < 2 menit |
| Uji E2E | *Merge* ke `main` + malam | < 15 menit |
| Uji beban | Mingguan + sebelum rilis | < 40 menit |
| DAST | Mingguan | < 30 menit |

**Aturan gerbang:** `main` **tidak** menerima *merge* bila ada tahap yang gagal.
Tidak ada *bypass* kecuali insiden produksi, dan setiap *bypass* wajib dicatat.
