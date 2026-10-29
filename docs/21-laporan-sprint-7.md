# 21 — Laporan Sprint 7: Pengaduan, Laporan, Stabilisasi
**Periode:** Sprint 7 · **Status:** Selesai (uji beban terpisah) · **Tanggal:** 2026-10-24

Sasaran (docs/11 §2): pengaduan + penugasan + SLA, dashboard, laporan & ekspor,
uji beban, uji penetrasi, perbaikan temuan. Milestone **M5 — Siap UAT**.

---

## 1. Hasil Kerja

| Kemampuan | Rujukan | Keterangan |
|-----------|---------|------------|
| Tabel `complaints` & `complaint_actions` | docs/05 §3.5 | + `chk_complaints_anon`, + CHECK penolakan beralasan |
| Kanal pengaduan publik | FR-PGD-01, UC-13 | Tanpa login; matriks memberi PB "C(anonim)" |
| Pengaduan **anonim** | FR-PGD-02, RULE-09 | Identitas dihilangkan di layanan + dijaga basis data |
| Kode pelacakan | FR-PGD-02 | 16 karakter, abjad tanpa huruf/angka yang tertukar |
| Mesin status | FR-PGD-03 | Satu pintu `ComplaintService::transition()` |
| Penugasan + notifikasi | FR-PGD-04 | Lewat jalur notifikasi in-app Sprint 6 |
| Tindak lanjut + catatan internal | FR-PGD-05 | Penanda "dibaca pelapor" per catatan |
| SLA dua tahap + eskalasi | FR-PGD-06, KEP-018 | Triase 2 hari, selesai 14 hari kerja |
| Ringkasan terbit ke portal | FR-PGD-07 | Opt-in per kasus, tanpa lokasi & pelapor |
| Penyaringan & pembatasan laju | FR-PGD-08 | Batas panjang, throttle rute, hitungan per jaringan |
| Dashboard pimpinan & operasional | FR-LAP-01, FR-LAP-02, UC-17 | Tata letak docs/07 §5.4 |
| Rekap penduduk per wilayah & kategori | FR-LAP-03 | |
| Rekap mutasi per periode | FR-LAP-04 | Mengikuti lingkup walau tabelnya tidak dijaga |
| Rekap layanan surat | FR-LAP-05 | Volume, rata-rata, % tepat SLA per jenis |
| Ekspor CSV | FR-LAP-07 | Selalu tercatat + jumlah baris (BR-41) |
| Buku Induk & Buku Mutasi | FR-LAP-06 | PDF mendatar, Permendagri 47/2016 |
| Sapuan otorisasi seluruh rute | docs/09 §4.3 | Dibangkitkan dari tabel rute |
| Uji penetrasi internal | docs/09 §4.5 | Laporan di docs/22 |

### Keputusan rancangan yang menentukan

**Pengaduan warga dilihat per PELAPOR, bukan per keluarga.** RULE-11
menyatukan keluarga karena surat memang diurus bersama — ayah mengambil SKTM
anaknya. Pengaduan tidak begitu: laporan tentang suami yang mengambil bantuan
sosial atas nama istrinya akan terbuka justru kepada orang yang diadukan bila
lingkupnya keluarga.

**RULE-09 ditegakkan dengan MENGHILANGKAN jalurnya, bukan memeriksa lalu
menolak.** Ketiga kolom identitas dipaksa `null` di satu tempat, sehingga
tidak ada nilai dari permintaan HTTP yang dapat sampai ke basis data — bahkan
bila formulir mengirimnya. Pemeriksaan yang menolak dapat dilewati jalur baru;
penghilangan tidak. CHECK basis data adalah jaring pengamannya, bukan
penggantinya.

**Keterlambatan SELALU dihitung kueri.** Perintah `sid:eskalasi-pengaduan`
hanya menaikkan prioritas dan mengirim notifikasi. Penjadwal yang mati membuat
desa kehilangan peringatan, bukan kehilangan kebenaran — pola yang sama dengan
penjadwalan terbit konten portal (Sprint 6).

**Dashboard: `null`, bukan nol.** Bagian yang bukan kewenangan pengguna tidak
dikirim sama sekali dan kartunya disembunyikan. "Pengaduan terbuka: 0" bagi
orang yang tidak boleh melihat pengaduan adalah pernyataan yang SALAH, dan di
layar pimpinan kesalahan seperti itu berakhir sebagai keputusan.

**Setiap laporan menyebut lingkupnya.** Dua orang dapat mencetak "Rekap
Penduduk" pada hari yang sama dan memperoleh angka berbeda — Admin Desa dan
Kepala Dusun — dan itu benar. Tanpa keterangan lingkup, keduanya dibandingkan
sebagai dua versi dari angka yang sama, lalu salah satunya dianggap salah;
yang dicurigai biasanya sistemnya, dan desa kembali menghitung dengan tangan.

---

## 2. Status Pengujian

| Lapis | Jumlah | Keterangan |
|-------|--------|------------|
| Seluruh rangkaian | **450** | 449 lulus, 1 sengaja dilewati |
| Pengaduan | 57 | lingkup, mesin status, kanal publik, panel petugas |
| Dashboard & laporan | 20 | hak lihat, lingkup, kalender desa, audit ekspor |
| Uji penetrasi | 10 | penugasan massal, RULE-09, injeksi, XSS, laju, IDOR |
| Sapuan otorisasi | 5 | dibangkitkan dari tabel rute |

Gerbang lain hijau: `pint`, `phpstan --memory-limit=1G`, `vue-tsc --noEmit`.

**Uji yang menjaga dirinya sendiri.** Dua uji di sprint ini dapat lulus tanpa
memeriksa apa pun bila pembantunya rusak: sapuan rute (daftar rutenya kosong)
dan uji injeksi (pembacaan hasilnya selalu kosong). Keduanya diberi
pemeriksaan non-kosong dan kasus kendali. Lapis otorisasi yang docs/09 §4.3
sebut "memblokir rilis" tidak boleh bergantung pada uji yang diam-diam berhenti
bekerja sementara layarnya tetap hijau.

---

## 3. Temuan Saat Menelusuri Alur di Peramban

Enam cacat ditemukan dengan mencoba, bukan oleh uji — konsisten dengan sprint
sebelumnya: uji menjaga aturan yang sudah dipikirkan, peramban menunjukkan
aturan yang belum terpikirkan.

### 3.1 Antrean petugas menelan formulir pengaduan warga ⚠
`GET /pengaduan` sudah dipakai formulir publik; rute antrean petugas
didaftarkan belakangan dengan URI yang sama. Laravel memetakan rute
berdasarkan pasangan metode+URI, sehingga **pendaftaran belakangan mengganti
yang terdahulu tanpa satu pun peringatan** — dan yang tersisa bagi warga
adalah halaman yang menuntut login. Antrean dipindah ke `/pengaduan/antrean`;
URL pendek diberikan kepada warga, yang membacanya dari poster dan portal.

### 3.2 Kode pelacakan memuat karakter yang tertukar ⚠
Kode pertama yang terbit berbunyi `QZHKSOHBO343ZECN`. Kode ini **ditulis ulang
dengan tangan** — warga mencatatnya di buku atau di balik struk — dan satu
huruf salah berarti pelapor anonim kehilangan pengaduannya selamanya, tanpa
seorang pun dapat mengembalikannya. Abjadnya kini membuang I, L, O, U, 0, 1.

### 3.3 "Batas penyelesaian" menampilkan tanggal triase
Baris antrean menampilkan satu tanggal — yang sedang ditunggu — sementara
halaman detail menampilkan dua baris bernama sendiri. Keduanya membaca muatan
yang sama, sehingga pengaduan yang masih `baru` memperlihatkan dua baris
bertanggal sama dan salah satunya berbohong.

### 3.4 Pilihan "— Semua —" pada saringan status tidak berfungsi
Controller memakai `?: 'terbuka'`, sehingga nilai kosong diam-diam kembali
menjadi "terbuka". Petugas yang mencari pengaduan lama akan menyimpulkan
pengaduan itu hilang dari sistem. Kini dibedakan antara parameter tidak ada
(bawaan) dan parameter kosong (sengaja: semua).

### 3.5 Kolom referensi kosong memicu peringatan pada setiap penduduk
`religion_code` dan kawannya boleh `null`; `$labels[null]` adalah offset null
yang sejak PHP 8.4 memicu deprecation — **satu baris log per penduduk** setiap
kali buku induk dicetak. Tidak terlihat di uji karena data uji selalu lengkap.
Penduduk tanpa tempat lahir juga mencetak baris berawalan koma
(", 20 Des 1975") di buku yang dibaca pemeriksa kecamatan.

### 3.6 "0 jam" terbaca seperti data hilang
Rata-rata waktu selesai di bawah satu jam dibulatkan menjadi nol — padahal
artinya justru terbaik: surat loket sering terbit dalam hitungan menit. Kini
dikatakan dengan kata. Jenis surat yang belum punya satu pun surat selesai
menampilkan tanda hubung, bukan nol.

---

## 4. Catatan Keamanan

Laporan lengkapnya: **docs/22-uji-penetrasi-sprint-7.md**. Tiga temuan yang
paling penting:

1. **Warga dapat membuka layar PETUGAS dan membaca catatan internal**
   (tingkat TINGGI). Matriks RBAC memberi Warga "R*" — membaca miliknya
   sendiri — dan izin itu sama dengan izin yang dijaga rute antrean petugas.
   Layar petugas menampilkan seluruh riwayat, termasuk catatan yang ditandai
   tidak terlihat pelapor. Lolos karena uji Bagian 3 dan 4 masing-masing
   menguji rutenya sendiri dan keduanya benar; yang tidak diuji adalah
   **persimpangannya**. Ditutup middleware `petugas` (EnsureStaffQueue).
2. **Audit log menautkan akun ke pengaduan anonim.** `AuditLogger::log()`
   dengan model menyimpan `entity_id` beserta pelakunya. Ditutup dengan
   `logWithoutActor()`, yang sengaja tidak menerima model.
3. **Alamat IP pelapor dapat di-JOIN dengan jejak login.** Kolomnya diganti
   sidik jari HMAC ber-pepper (KEP-018).

Kelas serangan yang dicoba dan gagal menembus: penugasan massal (`status`,
`is_published`, `reporter_user_id`, `tracking_code`), injeksi SQL lewat
pencarian, XSS lewat isi pengaduan, penebakan kode pelacakan, pembanjiran
kanal pengaduan, dan IDOR lintas wilayah.

---

## 5. Penyimpangan dari Roadmap

| Hal | Rencana | Yang dikerjakan | Alasan |
|-----|---------|-----------------|--------|
| Kolom tenggat | satu (`due_at`) | dua (+ `triage_due_at`) | KEP-018 — ketepatan triase hilang bila ditimpa |
| Kolom IP | `submitted_ip` | `submitted_ip_hash` | KEP-018 — alamat mentah dapat di-JOIN dengan audit log |
| `pengaduan.tugaskan` | hanya Admin Desa (lewat `*`) | + ketiga Kasi | Matriks memberi Kasi "U"; satu akun ber-2FA menjadi hambatan yang menjamin SLA terlampaui |
| FR-LAP-08 (8 buku lain) | prioritas S | **tidak dikerjakan** | Menuntut tabel Fase 2; docs/07 §2.3 memang menaruhnya di sana |
| Uji beban | di sprint ini | **dikerjakan terpisah** | Menuntut penyemaian 20.000 penduduk & k6; tidak bersinggungan dengan pekerjaan lain |

---

## 6. Yang Belum Dikerjakan

| Hal | Status | Rujukan |
|-----|--------|---------|
| Uji beban & volume | Belum dijalankan | docs/09 §4.5 — sesi terpisah |
| Uji penetrasi **pihak ketiga** | Belum | docs/11 §5 — paket pekerjaan terpisah |
| DAST (OWASP ZAP) di CI | Belum dipasang | docs/09 §4.5 |
| FR-LAP-08 — buku administrasi lain | Ditunda | Fase 2 |
| Notifikasi email & WhatsApp | Ditunda | FR-SRT-14 — Fase 2 |

---

## 7. Wajib Dilakukan Sebelum UAT

1. **Jalankan uji beban & volume** dan tutup temuannya. Kriteria docs/09 §4.5
   mengikat: p95 baca ≤ 500 ms, p95 tulis ≤ 1 s, galat < 0,1%, dan pencarian
   nama tetap ≤ 200 ms pada 20.000 penduduk.
2. **Jadwalkan uji penetrasi pihak ketiga.** Kriteria "0 Critical/High
   terbuka" berlaku pada uji ITU, bukan pada uji internal docs/22.
3. **Pasang `sid:eskalasi-pengaduan` di penjadwal produksi** dan pastikan
   penjadwalnya benar-benar berjalan. Tanpa itu desa kehilangan peringatan
   SLA — meski angka di layar tetap benar.
4. **Tetapkan batas SLA pengaduan bersama perangkat desa.** Bawaannya
   FR-PGD-06 (2 dan 14 hari kerja), tetapi ia dibaca dari `settings` justru
   agar dapat disepakati, bukan diasumsikan.
5. **Jalankan `RouteSweepTest` sebagai gerbang rilis**, bukan sebagai uji
   biasa. docs/09 §4.3 menyebut kegagalan lapis otorisasi memblokir rilis.
