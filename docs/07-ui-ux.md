# 07 — UI/UX & Information Architecture
**Proyek:** Sistem Informasi Desa (SID) · **Versi:** 1.0

---

## 1. Prinsip Desain

Diturunkan langsung dari karakteristik pengguna (SRS §2.2):

1. **Jelas lebih penting daripada indah.** Pengguna utama adalah perangkat desa dengan
   literasi digital rendah. Setiap layar menjawab: *"Saya di mana? Apa yang harus saya
   lakukan? Apa yang terjadi setelah ini?"*
2. **Bahasa warga, bukan bahasa sistem.** "Ajukan Surat", bukan "Create Request".
   "Belum Diperiksa", bukan "Pending Validation".
3. **Mobile-first, bukan mobile-juga.** Kadus & RT bekerja dari ponsel kelas bawah.
4. **Tidak ada jalan buntu.** Setiap galat menyebutkan langkah perbaikan; setiap layar
   kosong menawarkan tindakan pertama.
5. **Aman secara bawaan.** Data sensitif ter-masking secara default; membukanya adalah
   tindakan sadar yang tercatat.
6. **Hemat data.** Target < 500 KB untuk pemuatan pertama portal publik.
7. **Dapat dipercaya.** Tampilan harus terasa seperti layanan pemerintah yang resmi —
   bukan aplikasi komersial.

---

## 2. Arsitektur Informasi

### 2.1 Portal publik
```
/                         Beranda (sambutan, berita terbaru, layanan populer, statistik ringkas)
├── /profil
│   ├── /sejarah
│   ├── /visi-misi
│   ├── /wilayah            (peta & daftar dusun/RW/RT)
│   └── /pemerintahan       (struktur organisasi + foto perangkat)
├── /berita                 (daftar + kategori + pencarian)
│   └── /berita/{slug}
├── /pengumuman
├── /agenda
├── /layanan                ← HALAMAN PENTING: daftar surat, syarat, biaya, waktu
│   └── /layanan/{kode}     (detail satu layanan + tombol "Ajukan")
├── /statistik              (grafik penduduk — agregat)
├── /transparansi           (APBDes, realisasi, dokumen publik) — Fase 2
├── /pengaduan              (form lapor + lacak status)
├── /verifikasi/{kode}      (hasil pindai QR surat)
├── /unduhan                (Perdes, formulir, laporan)
└── /kontak
```

### 2.2 Portal warga (setelah login)
```
/warga
├── /beranda                Ringkasan: pengajuan aktif, notifikasi
├── /ajukan                 Pilih jenis surat → formulir
├── /pengajuan              Riwayat + status + unduh surat
├── /pengaduan              Pengaduan saya
├── /keluarga               Data diri & keluarga (baca; ajukan koreksi)
└── /profil                 Kontak, kata sandi, keamanan
```

### 2.3 Panel administrasi
```
/admin
├── /dashboard                      Ringkasan + tugas saya + peringatan SLA
├── /kependudukan
│   ├── /penduduk                   Daftar · detail · tambah · impor · ekspor
│   ├── /keluarga
│   ├── /mutasi                     Lahir · Mati · Pindah Datang · Pindah Keluar
│   ├── /wilayah                    Dusun / RW / RT
│   └── /anomali                    Laporan kualitas data
├── /pelayanan
│   ├── /masuk                      Antrean pengajuan (default: perlu tindakan saya)
│   ├── /arsip-surat
│   └── /jenis-surat                Template · formulir · alur persetujuan · penomoran
├── /pengaduan
├── /portal
│   ├── /berita · /pengumuman · /agenda · /halaman · /galeri · /unduhan
├── /keuangan                       (Fase 2)
├── /aset                           (Fase 2)
├── /bantuan-sosial                 (Fase 2)
├── /laporan                        Rekap penduduk · mutasi · layanan · buku administrasi
└── /pengaturan
    ├── /desa                       Identitas, logo, kop surat, pejabat
    ├── /pengguna · /peran
    ├── /referensi
    └── /log-aktivitas              Audit log
```

### 2.4 Navigasi menyesuaikan peran
Menu yang tidak diizinkan **tidak ditampilkan** (bukan hanya dinonaktifkan), agar
tidak membingungkan. Namun otorisasi tetap diperiksa di server (NFR-SEC-02) —
menyembunyikan menu adalah kenyamanan, bukan kontrol keamanan.

| Peran | Yang terlihat pertama saat login |
|-------|----------------------------------|
| Operator | Antrean pelayanan |
| Kasi/Kaur | Tugas yang perlu diverifikasi |
| Kepala Desa | Dashboard + daftar yang perlu disetujui |
| Kepala Dusun | Daftar penduduk wilayahnya |
| Admin Desa | Dashboard lengkap |
| Warga | Pengajuan saya |

---

## 3. Sistem Desain (Design Tokens)

### 3.1 Warna
Palet berangkat dari identitas pemerintahan: dapat dipercaya, tenang, tidak ramai.

| Token | Nilai | Penggunaan |
|-------|-------|-----------|
| `--color-primary` | `#1B5E3F` (hijau tua) | Tindakan utama, kop, tautan aktif |
| `--color-primary-hover` | `#174F35` | *Hover* |
| `--color-primary-soft` | `#E8F3ED` | Latar lembut, *badge* |
| `--color-accent` | `#B8860B` (emas tua) | Penekanan, elemen resmi |
| `--color-success` | `#15803D` | Berhasil, status selesai |
| `--color-warning` | `#B45309` | Perlu perhatian, melewati SLA |
| `--color-danger` | `#B91C1C` | Galat, tindakan destruktif |
| `--color-info` | `#1D4ED8` | Informasi netral |
| `--color-text` | `#111827` | Teks utama |
| `--color-text-muted` | `#4B5563` | Teks sekunder (kontras ≥ 4.5:1 di latar putih) |
| `--color-border` | `#D1D5DB` | Garis pemisah |
| `--color-surface` | `#FFFFFF` | Kartu |
| `--color-background` | `#F7F8F7` | Latar halaman |

**Aturan wajib:**
- Warna **tidak pernah** menjadi satu-satunya pembawa makna — status selalu
  disertai ikon **dan** teks (WCAG 1.4.1; penting karena buta warna lazim).
- Kontras teks ≥ 4.5:1; teks besar ≥ 3:1; komponen UI ≥ 3:1.
- Mode gelap: **Fase 2**, bukan prioritas (pengguna bekerja siang hari di kantor).

### 3.2 Tipografi
| Token | Nilai |
|-------|-------|
| Font | `Inter` (variabel) dengan *fallback* `system-ui, -apple-system, "Segoe UI", Roboto, sans-serif` |
| Skala | 12 / 14 / 16 / 18 / 20 / 24 / 30 / 36 px |
| Dasar | **16 px** (jangan lebih kecil — banyak pengguna berusia > 45 tahun) |
| Tinggi baris | 1.5 untuk isi, 1.25 untuk judul |
| Berat | 400 isi · 500 label · 600 judul |
| Lebar baris | Maks 70 karakter untuk teks panjang |

Font dimuat **lokal** (*self-hosted*), bukan dari CDN pihak ketiga — demi privasi,
kinerja di jaringan lemah, dan ketahanan bila CDN terblokir.

### 3.3 Spasi, Radius, Bayangan
- Spasi: skala 4 px → `4, 8, 12, 16, 24, 32, 48, 64`.
- Radius: `4px` (input, badge) · `8px` (kartu, tombol) · `12px` (modal).
- Bayangan: dua tingkat saja — `sm` untuk kartu, `lg` untuk *overlay*. Hindari
  bayangan berlebihan; tampilan harus terasa padat dan resmi.
- Target sentuh minimum **44 × 44 px**.

---

## 4. Pustaka Komponen

| Komponen | Ketentuan kunci |
|----------|-----------------|
| **Tombol** | Varian: utama, sekunder, bahaya, teks. Status *loading* mengunci tombol (cegah klik ganda). Tindakan destruktif selalu memerlukan konfirmasi |
| **Field formulir** | Label **di atas** field (bukan *placeholder* sebagai label). Penanda wajib eksplisit. Galat di bawah field + ringkasan di atas formulir |
| **Tabel data** | Header *sticky*, pilihan kolom, urutan, paginasi, *bulk action*. **Di layar < 768 px berubah menjadi daftar kartu**, tidak pernah menggulir horizontal |
| **Pencarian penduduk** | *Autocomplete* dengan *debounce* 300 ms; menampilkan nama, umur, dusun, NIK ter-masking agar petugas yakin memilih orang yang benar |
| **Badge status** | Ikon + teks + warna. Padanan tetap: abu=Draf, biru=Diajukan, kuning=Perlu Revisi, ungu=Diverifikasi, hijau=Diterbitkan, merah=Ditolak |
| **Stepper** | Menunjukkan tahap pengajuan: Diajukan → Diperiksa → Disetujui → Selesai |
| **Empty state** | Ilustrasi sederhana + penjelasan + satu tombol tindakan ("Belum ada pengajuan. Mulai dengan menekan *Ajukan Surat*.") |
| **Field data sensitif** | Default ter-masking `3201••••••••1234` + tombol mata. Menekan tombol **mencatat audit log** dan menampilkan pemberitahuan singkat |
| **Unggah berkas** | *Drag-drop* + pilih berkas + **kamera langsung di ponsel**. Pratinjau gambar. Kompresi di sisi klien sebelum unggah (hemat kuota) |
| **Notifikasi** | *Toast* untuk konfirmasi singkat; *banner* untuk hal yang perlu tindakan; lonceng untuk riwayat |
| **Dialog konfirmasi** | Judul berupa pertanyaan, isi menjelaskan akibat, tombol memakai kata kerja ("Hapus Penduduk", bukan "OK") |
| **Skeleton** | Untuk pemuatan > 300 ms; bukan *spinner* penuh halaman |

---

## 5. Wireframe Layar Kunci (low-fidelity)

### 5.1 Antrean pelayanan (panel admin — layar paling sering dipakai)
```
┌──────────────────────────────────────────────────────────────────────────┐
│ ☰  SID Desa Sukamaju          🔍 Cari penduduk/surat…    🔔 3   Budi ▾  │
├────────────┬─────────────────────────────────────────────────────────────┤
│ ▸ Dashboard│  Pelayanan › Pengajuan Masuk                                │
│ ▾ Penduduk │                                                             │
│ ▾ Pelayanan│  ┌─ Perlu tindakan saya (4) ─┬─ Semua (37) ─┬─ Terlambat(2)┐│
│   • Masuk ◀│  └───────────────────────────┴──────────────┴──────────────┘│
│   • Arsip  │  Jenis: [Semua ▾]  Status: [Semua ▾]  Periode: [Okt 2026 ▾]│
│   • Jenis  │                                             [+ Layani Warga]│
│ ▸ Pengaduan│                                                             │
│ ▸ Portal   │  ┌─────────────────────────────────────────────────────────┐│
│ ▸ Laporan  │  │ ⚠ SRT-2026-000121 · 3 hari (SLA 1 hari)                ││
│ ▸ Pengaturan│ │ SKTM · Siti Aminah (41 th) · Dusun Krajan RT 003        ││
│            │  │ 🔵 Diajukan        [Lihat] [Verifikasi] [Minta Revisi]  ││
│            │  ├─────────────────────────────────────────────────────────┤│
│            │  │ SRT-2026-000122 · 2 jam lalu                            ││
│            │  │ Surat Domisili · Ahmad Hidayat (52 th) · RT 001         ││
│            │  │ 🟣 Diverifikasi    [Lihat] [Setujui & Terbitkan]        ││
│            │  └─────────────────────────────────────────────────────────┘│
│            │                                      ‹ 1 2 3 … 8 ›         │
└────────────┴─────────────────────────────────────────────────────────────┘
```
**Keputusan desain:** tab default adalah **"Perlu tindakan saya"** — petugas langsung
melihat pekerjaannya tanpa menyaring. Keterlambatan SLA tampil sebagai penanda visual
pertama pada kartu.

### 5.2 Detail pengajuan & verifikasi
```
┌──────────────────────────────────────────────────────────────────────────┐
│ ‹ Kembali      SRT-2026-000121 · Surat Keterangan Tidak Mampu           │
├──────────────────────────────────────────────────────────────────────────┤
│  ①Diajukan ──── ②Diperiksa ──── ③Disetujui ──── ④Selesai                │
│   ✓ 2 Okt         ⬤ sekarang       ○               ○                     │
├────────────────────────────────┬─────────────────────────────────────────┤
│ DATA PEMOHON                   │ LAMPIRAN SYARAT                         │
│ Nama   : Siti Aminah           │ ✓ Fotokopi KK        [lihat]            │
│ NIK    : 3201••••••••1234 👁   │ ✓ Fotokopi KTP       [lihat]            │
│ Lahir  : 17 Mar 1985 (41 th)   │ ✗ Surat Pengantar RT  — belum           │
│ Alamat : Jl. Melati 12, RT 003 │                                         │
│ Status : Kawin · Petani        │ RIWAYAT                                 │
│          [Lihat data lengkap]  │ 2 Okt 09:12  Diajukan oleh pemohon      │
│                                │                                         │
│ ISI PERMOHONAN                 │ CATATAN INTERNAL                        │
│ Keperluan: Pengajuan beasiswa  │ ┌─────────────────────────────────────┐ │
│ anak (SMA Negeri 1 Sukaraja)   │ │ (tidak terlihat oleh pemohon)       │ │
│                                │ └─────────────────────────────────────┘ │
├────────────────────────────────┴─────────────────────────────────────────┤
│ [👁 Pratinjau Surat]   [Minta Revisi]  [Tolak]  [✓ Verifikasi]           │
└──────────────────────────────────────────────────────────────────────────┘
```
**Keputusan desain:** syarat yang belum lengkap ditandai jelas agar petugas tidak
perlu membuka setiap lampiran. "Pratinjau Surat" tersedia **sebelum** persetujuan,
sehingga kesalahan ditemukan sebelum nomor terbit (nomor tidak boleh terbuang — ADR-001).

### 5.3 Pengajuan surat oleh warga (tampilan ponsel)
```
┌─────────────────────┐   ┌─────────────────────┐   ┌─────────────────────┐
│ ‹ Ajukan Surat      │   │ ‹ SKTM       (2/3)  │   │ ‹ Ringkasan  (3/3)  │
├─────────────────────┤   ├─────────────────────┤   ├─────────────────────┤
│ Pilih layanan:      │   │ ●━━━●━━━○           │   │ ●━━━●━━━●           │
│                     │   │                     │   │                     │
│ 🔍 [cari layanan]   │   │ Pemohon             │   │ Jenis               │
│                     │   │ ┌─────────────────┐ │   │ SKTM                │
│ ┌─────────────────┐ │   │ │ Siti Aminah     │ │   │                     │
│ │ Surat Keterangan│ │   │ │ 3201••••••1234  │ │   │ Pemohon             │
│ │ Tidak Mampu     │ │   │ │ (data terkunci) │ │   │ Siti Aminah         │
│ │ ⏱ 1 hari · Gratis│ │   │ └─────────────────┘ │   │                     │
│ │              ›  │ │   │                     │   │ Keperluan           │
│ ├─────────────────┤ │   │ Keperluan *         │   │ Beasiswa anak       │
│ │ Surat Keterangan│ │   │ ┌─────────────────┐ │   │                     │
│ │ Domisili        │ │   │ │                 │ │   │ Lampiran (2)        │
│ │ ⏱ 1 hari · Gratis│ │   │ └─────────────────┘ │   │ ✓ KK  ✓ KTP         │
│ │              ›  │ │   │                     │   │                     │
│ ├─────────────────┤ │   │ Lampiran wajib      │   │ ⓘ Estimasi selesai: │
│ │ Surat Keterangan│ │   │ Fotokopi KK *       │   │   5 Okt 2026        │
│ │ Usaha           │ │   │ [📷 Kamera][📁 File]│   │                     │
│ │ ⏱ 2 hari · Gratis│ │   │ ✓ kk.jpg (820 KB) │   │ [✓ Kirim Pengajuan] │
│ └─────────────────┘ │   │                     │   │                     │
│                     │   │ [Lanjut →]          │   │ [‹ Perbaiki]        │
└─────────────────────┘   └─────────────────────┘   └─────────────────────┘
```
**Keputusan desain:** tiga langkah pendek, bukan satu formulir panjang. Data identitas
**terkunci** (warga tidak boleh mengubah data resmi — RULE-11 & integritas data).
Tombol kamera lebih dulu daripada pilih berkas, karena warga memfoto dokumen langsung.

### 5.4 Dashboard Kepala Desa
```
┌──────────────────────────────────────────────────────────────────────────┐
│ Dashboard · Desa Sukamaju                        Oktober 2026  [Unduh ▾]│
├──────────────────────────────────────────────────────────────────────────┤
│ ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐                     │
│ │ PENDUDUK │ │ KELUARGA │ │ LAYANAN  │ │ PENGADUAN│                     │
│ │  5.231   │ │  1.487   │ │  37      │ │   4      │                     │
│ │ ▲ 12 bln │ │ ▲ 3      │ │ 2 telat ⚠│ │ 1 telat ⚠│                     │
│ └──────────┘ └──────────┘ └──────────┘ └──────────┘                     │
├─────────────────────────────────────┬────────────────────────────────────┤
│ TREN LAYANAN (12 bulan)             │ PERLU PERSETUJUAN ANDA (3)         │
│  ▁▂▃▅▄▆▇▆▅▇█▆                       │ • SKTM — Siti Aminah      [Buka]   │
│  Rata-rata selesai: 4,2 jam         │ • Domisili — Ahmad H.     [Buka]   │
│  Tepat waktu: 94%                   │ • SKU — Dewi Lestari      [Buka]   │
├─────────────────────────────────────┼────────────────────────────────────┤
│ KOMPOSISI PENDUDUK                  │ PENGADUAN TERBUKA                  │
│  ◀ Piramida penduduk per kelompok   │ ⚠ Jalan rusak RT 005 (15 hari)     │
│    umur & jenis kelamin ▶           │ • Lampu jalan mati RT 002 (3 hari) │
└─────────────────────────────────────┴────────────────────────────────────┘
```
**Keputusan desain:** dashboard pimpinan menampilkan **pekerjaan yang menunggu
dirinya** di posisi menonjol — bukan sekadar angka. Tanpa ini, persetujuan menumpuk
dan O-2 (SLA 1 jam) tidak tercapai.

### 5.5 Beranda portal publik (ponsel)
```
┌─────────────────────┐
│ 🏛 Desa Sukamaju  ☰ │
├─────────────────────┤
│  Selamat datang di  │
│  website resmi      │
│  Desa Sukamaju      │
│                     │
│  [Ajukan Surat →]   │  ← tindakan utama, di atas garis lipat
│  [Lapor Pengaduan]  │
├─────────────────────┤
│ LAYANAN POPULER     │
│ • SKTM     ⏱1 hari  │
│ • Domisili ⏱1 hari  │
│ • SKU      ⏱2 hari  │
│         [Semua →]   │
├─────────────────────┤
│ BERITA TERBARU      │
│ ┌─────────────────┐ │
│ │ [gambar]        │ │
│ │ Musdes RKP 2027 │ │
│ │ 2 Okt 2026      │ │
│ └─────────────────┘ │
├─────────────────────┤
│ DESA DALAM ANGKA    │
│ 5.231 jiwa          │
│ 1.487 keluarga      │
│ 4 dusun · 18 RT     │
│      [Statistik →]  │
└─────────────────────┘
```
**Keputusan desain:** dua tombol tindakan di atas garis lipat. Warga datang ke situs
desa untuk **mengurus sesuatu**, bukan membaca berita — berita diletakkan setelahnya.

---

## 6. Alur Pengguna Kritis

### 6.1 Warga: dari butuh surat sampai menerima
```
Buka portal → [Ajukan Surat] → Belum punya akun?
                                  ├── Daftar (NIK + No. KK + nama + no. HP)
                                  │     → verifikasi petugas (≤ 1 hari kerja)
                                  │     → notifikasi "akun aktif"
                                  └── Login
  → Pilih layanan → Isi formulir → Unggah syarat → Kirim
  → Terima nomor tiket + estimasi
  → (notifikasi) "Sedang diperiksa" → "Perlu revisi" / "Disetujui"
  → Unduh PDF atau ambil cetakan di kantor
```
**Titik putus yang harus dijaga:** verifikasi akun adalah hambatan terbesar.
Mitigasi: proses verifikasi tampil sebagai tugas mencolok di panel admin dengan SLA
1 hari kerja, dan warga tetap dapat mengajukan secara *walk-in* tanpa akun.

### 6.2 Operator: melayani warga yang datang (target ≤ 10 interaksi — NFR-USA-06)
```
Dashboard → [Layani Warga] → cari nama/NIK (autocomplete) → pilih penduduk
 → pilih jenis surat → formulir (identitas sudah terisi) → simpan
 → (bila operator juga berwenang) langsung ajukan ke verifikator
```

### 6.3 Kadus: koreksi data dari lapangan (ponsel)
```
Login → Penduduk wilayah saya → cari warga → [Usulkan Koreksi]
 → pilih field → isi nilai baru → alasan → (opsional) foto bukti → kirim
 → masuk antrean persetujuan Admin Desa → notifikasi hasil
```

---

## 7. Kebergunaan & Aksesibilitas (Checklist Implementasi)

**Wajib, diperiksa pada setiap *pull request*:**
- [ ] Semua *input* punya `<label>` yang tertaut (bukan hanya *placeholder*).
- [ ] Navigasi penuh dengan papan tombol; indikator fokus terlihat jelas (≥ 2 px).
- [ ] Kontras diperiksa dengan alat otomatis (axe/Lighthouse) di CI.
- [ ] Gambar punya `alt` yang bermakna; gambar dekoratif `alt=""`.
- [ ] Galat formulir: diumumkan ke pembaca layar (`aria-live`), fokus pindah ke
      field pertama yang galat.
- [ ] Tabel punya `<caption>` dan `<th scope>`.
- [ ] Tidak ada informasi yang hanya disampaikan lewat warna.
- [ ] Zoom sampai 200% tidak memotong isi.
- [ ] Target sentuh ≥ 44 px dengan jarak antar-target ≥ 8 px.
- [ ] Bekerja tanpa JavaScript untuk halaman **portal publik** (*progressive enhancement*).
- [ ] Animasi menghormati `prefers-reduced-motion`.
- [ ] Bahasa halaman `lang="id"`.

**Pola pesan galat** — selalu tiga bagian: *apa yang terjadi · mengapa · apa yang harus dilakukan*.
| ✗ Buruk | ✓ Baik |
|---------|--------|
| "Error 422: validation failed" | "Pengajuan belum dapat dikirim karena **Surat Pengantar RT** belum diunggah. Unggah berkas tersebut, lalu tekan Kirim kembali." |
| "NIK invalid" | "NIK harus 16 angka. Anda memasukkan 15 angka." |
| "Unauthorized" | "Anda tidak memiliki akses untuk menyetujui surat. Hubungi Sekretaris Desa untuk penambahan hak akses." |

---

## 8. Kinerja Frontend

| Target | Nilai | Cara mencapai |
|--------|-------|---------------|
| LCP portal (4G) | ≤ 2,5 s | SSR, gambar `webp` + `srcset` + `loading="lazy"`, CSS kritis *inline* |
| Transfer awal portal | ≤ 500 KB | Tanpa *framework* berat di portal; font *self-hosted* dengan `font-display: swap` |
| JS *bundle* panel admin | ≤ 300 KB (gzip) | *Code splitting* per rute, `lazy import` untuk grafik & tabel besar |
| INP | ≤ 200 ms | Virtualisasi daftar panjang, *debounce* pencarian |
| CLS | ≤ 0,1 | Dimensi gambar eksplisit, *skeleton* seukuran isi akhir |
| PWA | Dapat dipasang | *Service worker* men-*cache shell* + aset; data tetap daring |

**Perilaku saat jaringan buruk:**
- Formulir panjang menyimpan *draft* ke penyimpanan lokal tiap 10 detik.
- Pengiriman gagal → tetap di halaman, data terjaga, tombol "Coba Lagi".
- Indikator daring/luring di panel admin.
- Gambar dikompresi di sisi klien sebelum unggah (maks sisi panjang 1600 px).

---

## 9. Pedoman Suara & Istilah

| Jangan | Gunakan |
|--------|---------|
| Submit | Kirim |
| Pending | Belum diperiksa / Menunggu verifikasi |
| Approve / Reject | Setujui / Tolak |
| Delete | Hapus |
| Dashboard | Dashboard (sudah umum) atau Ringkasan |
| User | Pengguna |
| Resident | Penduduk / Warga |
| Error occurred | Terjadi gangguan |
| Invalid input | Isian belum sesuai |
| Export | Unduh data |
| Filter | Saring |

- Sapaan: netral & sopan, tanpa "kamu". Gunakan "Anda" atau kalimat tanpa subjek.
- Hindari singkatan teknis tanpa penjelasan. "SLA" → "batas waktu layanan".
- Nama layanan ditulis **penuh** di tempat pertama kali muncul:
  "Surat Keterangan Tidak Mampu (SKTM)".

---

## 10. Hasil Kerja Desain (Deliverables)

| Hasil kerja | Format | Kapan |
|-------------|--------|-------|
| *Design token* | JSON + CSS variables | Sprint 1 |
| Pustaka komponen | Figma + implementasi kode (Storybook) | Sprint 1–3 |
| *Wireframe* seluruh layar MVP | Figma | Sebelum sprint terkait |
| *High-fidelity mockup* 10 layar kunci | Figma | Sprint 1–2 |
| Prototipe teruji dengan 5 pengguna nyata | Figma + catatan uji | Sebelum Sprint 4 |
| Panduan pengisian konten portal | PDF | Sebelum go-live |

> **Catatan uji kebergunaan:** lakukan uji dengan **minimal 3 perangkat desa dan
> 3 warga nyata** (bukan rekan tim) sebelum pengembangan modul persuratan selesai.
> Satu sesi 45 menit per orang sudah cukup untuk menemukan masalah terbesar, dan
> jauh lebih murah daripada memperbaikinya setelah rilis.
