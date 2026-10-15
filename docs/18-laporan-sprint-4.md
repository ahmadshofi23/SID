# 18 — Laporan Sprint 4: Persuratan
**Periode:** Sprint 4 · **Status:** Selesai · **Tanggal:** 2026-10-15

Sasaran (docs/11 §2): jenis surat + seeder katalog 22 surat (dok. 13), editor
template, formulir dinamis, pengajuan *walk-in*, mesin status, dan **penomoran
otomatis**.

---

## 1. Hasil Kerja

| Kemampuan | Rujukan | Keterangan |
|-----------|---------|------------|
| Katalog 22 jenis surat | dok. 13 | 8 aktif, 14 siap diaktifkan |
| Editor jenis surat & template | FR-SRT-01, FR-SRT-02 | Dengan pratinjau & validasi variabel |
| Formulir dinamis | ADR-002 | Dari `form_schema`, divalidasi di server |
| Pengajuan loket | FR-SRT-05 | Pencarian pemohon + isian per jenis surat |
| Mesin status | FR-SRT-07 | Transisi tidak sah → 409 |
| Alur 1/2/3 tahap | dok. 13 §4 | F1, F2, F3 |
| **Penomoran otomatis** | FR-SRT-08, ADR-001 | Bebas duplikat & bebas celah |
| Penerbitan surat | FR-SRT-10, RULE-04 | Snapshot permanen (ADR-003) |
| Arsip & cetak | FR-SRT-11, FR-SRT-13 | Lembar F4, kop desa, QR |
| Pembatalan surat | FR-SRT-12, RULE-05 | Nomor tidak dipakai ulang |
| Verifikasi QR publik | FR-SRT-14 | Tanpa login, tanpa membocorkan data |

### Keputusan rancangan yang menentukan

**Nomor terbit hanya pada persetujuan, di dalam satu transaksi dengan suratnya.**
Pengambilan nomor, perenderan, penyimpanan snapshot, dan perubahan status berada
dalam satu transaksi. Bila salah satu gagal, nomornya ikut batal — tidak ada nomor
terpakai tanpa surat, dan tidak ada surat tanpa nomor.

**Tiga lapis pengaman penomoran, bukan satu.** `INSERT … ON DUPLICATE KEY` lalu
`SELECT … FOR UPDATE` (ADR-001), ditambah indeks unik
`(letter_type_id, issued_year, sequence_number)` sebagai jaring pengaman basis
data. Lapis ketiga itu ada justru untuk keadaan ketika lapis pertama dan kedua
keliru.

**Badan surat disusun di server dan disimpan utuh.** Pembuka, blok identitas, isi
pokok, dan penutup dirender sekali saat terbit lalu disimpan di
`letters.rendered_html`. Halaman cetak tidak merakit bagian mana pun. Tanpa ini,
menyunting halaman cetak akan mengubah tampilan surat yang sudah terbit
bertahun-tahun lalu — bertentangan dengan ADR-003.

**Template surat dibersihkan sebagai masukan pengguna.** Template adalah HTML yang
disunting petugas dari peramban dan ditampilkan dengan `v-html`. Mesin template umum
(Blade, Twig) mengeksekusi kode; perender di sini tidak mengeksekusi apa pun, dan
hasilnya dilewatkan `App\Support\HtmlSanitizer` dengan daftar izin tag.

**Mesin status ditulis sebagai tabel, bukan rangkaian `if`.**
`LetterRequest::TRANSITIONS` memuat seluruh transisi sah dalam satu layar. Status
akhir memetakan ke daftar kosong: surat yang terbit, ditolak, atau dibatalkan tidak
dapat dihidupkan kembali — pemohon mengajukan ulang sebagai permintaan baru, agar
riwayatnya tetap utuh untuk audit.

**Pengecualian RULE-06 untuk layanan loket dicatat, bukan didiamkan.** Di desa
kecil, operator yang mengetik pengajuan dan admin yang menerbitkan sering orang yang
sama. Memblokirnya membuat sistem tidak dapat dipakai; karena itu kanal `walk_in`
dikecualikan tetapi setiap kejadiannya masuk audit log.

---

## 2. Status Pengujian

```
Architecture     4 uji
Authorization   30 uji
Unit            35 uji
Feature        141 uji   (+45 dari Sprint 4)
──────────────────────────────────────────
TOTAL          210 uji · 716 asersi · semua lolos
PHPStan level 6 · 0 error    vue-tsc · 0 error    Pint · bersih
```

### TC-SRT-08 — penomoran bebas duplikat ⚠ KRITIS

Diuji dengan **proses sistem operasi terpisah** (`pcntl_fork`), bukan pemanggilan
berurutan. Uji berurutan akan selalu lulus — termasuk bila penguncian dihapus
seluruhnya — karena ia hanya membuktikan penambahan satu demi satu berfungsi.

| Varian | Hasil |
|--------|-------|
| 20 penerbitan bersamaan | 20 nomor unik, urut 1–20, tanpa celah |
| (a) transaksi dibatalkan | Nomor dipakai ulang, deret tidak berlubang |
| (b) penghitung tahun baru belum ada | 10 permintaan bersamaan tetap unik |
| (c) dua jenis surat | Deret terpisah |
| (d) mode nomor tunggal | Satu deret untuk semua jenis |

**Diverifikasi terbalik.** Kedua pengaman sengaja dirusak untuk memastikan ujinya
benar-benar dapat gagal:

| Kode dirusak | Akibat yang tertangkap |
|--------------|------------------------|
| `INSERT … ON DUPLICATE KEY` dihapus | Penghitung tidak bertambah sama sekali |
| `FOR UPDATE` dihapus | 20 proses mendapat nomor 1 yang sama |

---

## 3. Temuan Saat Menelusuri Alur di Peramban

Seluruh temuan di bawah ditemukan dengan **menjalankan alur persuratan sendiri di
peramban** — mengajukan, memverifikasi, menerbitkan, mencetak — setelah seluruh
pengujian otomatis hijau. Tidak satu pun tertangkap pengujian, dan alasannya sama
untuk semuanya: ujinya memeriksa hal yang benar, dan jawabannya memang benar.

### 3.1 Zona waktu: UTC vs kalender desa ⚠

Aplikasi menyimpan waktu dalam UTC; kantor desa bekerja menurut WIB/WITA/WIT.
Selisih tujuh jam tidak terlihat sepanjang tahun, lalu muncul sebagai:

| Gejala | Akibat |
|--------|--------|
| Batas waktu SLA "17 Oktober pukul 06.59" | Seharusnya akhir hari Jumat 16 Oktober. Laporan SLA menganggap surat terlambat masih tepat waktu |
| Nomor surat di pergantian tahun | Surat 1 Januari pukul 02.00 WIB bernomor `…/XII/2026` sementara buku agenda desa mencatatnya sebagai surat pertama 2027 |
| `letters.issued_year` | Dihitung MySQL dari `YEAR(issued_at)` UTC, sehingga **indeks unik `uq_letters_seq` menjaga pasangan tahun yang salah** — nomor 1 tahun baru dapat terbit dua kali tanpa ditolak basis data |

**Perbaikan:** `App\Support\VillageTime` menjadi satu-satunya sumber kalender desa
(zona diambil dari `village_profile.timezone`, bukan ditulis tetap — Indonesia punya
tiga zona waktu). Penghitung, token nomor, tanggal tercetak, masa berlaku, dan batas
waktu SLA semuanya memakainya. `issued_year` diubah menjadi kolom biasa yang ditulis
aplikasi (migrasi `2026_01_03_000200`), agar jaring pengaman basis data tidak
bergantung pada zona waktu server.

Enam uji regresi ditambahkan, dan **diverifikasi terbalik**: mengembalikan
`VillageTime::zone()` ke `UTC` menggagalkan empat di antaranya.

### 3.2 Penolakan 403/409 tidak terlihat petugas ⚠

Inertia menangani balasan 403 atas sebuah POST dengan **tidak mengubah apa pun di
layar**. Kasi Pemerintahan menekan "Verifikasi" pada SKTM — yang tahap pertamanya
milik Kasi Kesejahteraan — dan tidak terjadi apa-apa. Petugas menyimpulkan sistemnya
rusak, lalu menekannya berulang kali.

Pengujian tetap hijau karena uji memeriksa **kode status**, dan kode statusnya
memang 403. Yang membedakan adalah header `X-Inertia`.

**Perbaikan:** penolakan pada permintaan Inertia dialihkan kembali dengan pesan
kesalahan yang tampil sebagai notifikasi (`bootstrap/app.php`). Ditambah: tombol
yang bukan kewenangan pengguna tidak lagi ditampilkan, diganti keterangan
*"Tahap pemeriksaan ini kewenangan Kasi Kesejahteraan"* — petugas perlu tahu kepada
siapa berkasnya diteruskan.

### 3.3 Semantik tahap: `approve` bukan verifikasi

`stepCount()` menghitung seluruh tahap alur, termasuk tahap `approve`. Pada alur F2
(`verify` → `approve`) itu berarti sistem menunggu **dua** verifikasi, padahal tahap
kedua adalah penerbitannya sendiri. Akibatnya pengajuan F2 mandek di status
`diajukan` dan tombol terbit tidak pernah muncul.

**Perbaikan:** `verificationStepCount()` dipisah dari `stepCount()`.

### 3.4 Cacat pada lembar surat yang tercetak

Terlihat hanya setelah surat betul-betul dicetak ke layar:

| Tercetak | Seharusnya |
|----------|------------|
| `Kepala Desa Desa Sukamaju` | `Kepala Desa Sukamaju` |
| `Dusun Dusun Krajan` | `Dusun Krajan` |
| `Surat ini berlaku sampai 2027-01-15` | `… sampai dengan 15 Januari 2027` |
| NIK tersamar `3201••••••••0002` | NIK lengkap — surat dicocokkan dengan KTP oleh bank/sekolah |

Ketiga yang pertama adalah kelas kesalahan yang sama: menyambung teks tanpa memeriksa
apa yang sudah ada di dalamnya. Datanya valid, jadi tidak ada uji yang gagal.

NIK tersamar berasal dari keputusan menyimpan snapshot tanpa NIK polos — benar untuk
snapshot, tetapi blok identitas surat resmi memang harus memuat NIK lengkap.
Diperbaiki dengan memindahkan penyusunan badan surat ke server
(`LetterBodyComposer`): NIK lengkap masuk ke `rendered_html`, snapshot tetap
tersamar, dan pengungkapannya **selalu** tercatat di audit log (sebelumnya hanya bila
template memuat `{{nik}}` — syarat yang membuat sebagian pengungkapan lolos tanpa
jejak).

### 3.5 Pratinjau template menghasilkan kalimat terpotong

Pratinjau SKTM berbunyi *"Surat keterangan ini dibuat untuk keperluan: ."* karena
isian dinamisnya kosong. Petugas yang sedang menyunting redaksi tidak dapat menilai
hasilnya. Pratinjau kini mengisi setiap isian dengan nilai contoh, memakai **opsi
pertama yang didefinisikan desa** untuk field pilihan — bukan teks karangan.

### 3.6 Penanda menu aktif menyala rangkap

Pola `/surat` ikut cocok dengan `/surat/arsip`, sehingga tiga menu tampak aktif
bersamaan dan penanda posisi kehilangan artinya. Kini hanya pola terpanjang yang
cocok yang dianggap aktif.

---

## 4. Catatan Keamanan

| Hal | Penanganan |
|-----|-----------|
| Template HTML dari petugas | Daftar izin tag (`HtmlSanitizer`); `<script>`, `style`, `on*`, `javascript:` dibuang |
| NIK pada surat | Selalu tercatat di audit log saat penerbitan (RULE-07) |
| Halaman verifikasi publik | Nama tersamar; tanpa NIK, alamat, isi surat, maupun snapshot |
| Kode verifikasi | 32 karakter acak `random_int`, tanpa huruf yang mudah tertukar; percobaan gagal dicatat |
| Lingkup wilayah & keluarga | `LetterRequestRepository` & `LetterRepository` — di luar lingkup → **404**, bukan 403 |
| Snapshot ke peramban | Tidak dikirim; seluruh isi surat sudah ada di `rendered_html` |

---

## 5. Penyimpangan dari Roadmap

Dua hal berbeda dari rencana di `docs/11 §2`. Keduanya disengaja.

**Editor template bukan WYSIWYG, melainkan HTML dengan pratinjau.** Roadmap menulis
"template WYSIWYG". Editor WYSIWYG menghasilkan HTML yang tidak dapat diramalkan —
gaya dari Word, `<div>` bersarang, atribut `style` — justru pada bagian yang harus
dibersihkan ketat karena ditampilkan dengan `v-html`. Yang disediakan: kotak HTML
dengan panel variabel yang dapat diklik-salin, pratinjau sekali klik berisi data
contoh, penolakan variabel tak dikenal, dan pemberitahuan ketika ada tag yang
dibuang. Bila desa menghendaki WYSIWYG, ia dapat ditambahkan di atas lapis
pembersih yang sudah ada.

**Arsip, pembatalan, dan verifikasi QR ikut selesai di Sprint 4**, padahal
dijadwalkan Sprint 5. Ketiganya tidak dapat dipisahkan dari penerbitan: surat yang
dapat terbit tetapi tidak dapat dicari, dibatalkan, atau diperiksa keasliannya belum
dapat diperagakan sebagai fitur. Yang benar-benar tersisa untuk Sprint 5 adalah
render PDF (Gotenberg), unggah berkas, dan pelacakan status; pengajuan daring oleh
warga dipindahkan ke Sprint 6 bersama registrasi akun warga (KEP-013).

---

## 6. Yang Belum Dikerjakan

| Hal | Rencana |
|-----|---------|
| Unggah berkas syarat | Sprint 5 |
| Render PDF (Gotenberg) | Sprint 5, ADR-003 |
| Pengajuan daring oleh warga | Sprint 6, bersama registrasi & verifikasi akun warga (KEP-013) |
| Penyuntingan alur persetujuan | Sprint 7, bersama pengaturan peran |
| Tawaran mutasi otomatis dari SKL/SKM | Sprint 5 (dok. 13 §5.4, §5.5) |

---

## 7. Wajib Dilakukan Sebelum Layanan Dibuka ke Warga

Ditampilkan juga sebagai peringatan di layar **Jenis Surat**:

1. **Pola penomoran** disamakan dengan arsip surat asli desa. ⚠ Mengubahnya setelah
   ada surat terbit menghasilkan deret yang tidak konsisten.
2. **Kode klasifikasi arsip** diisi dari arsip desa — sengaja dikosongkan karena
   berbeda antar daerah dan antar versi peraturan kearsipan.
3. **Pejabat penanda tangan** diisi di Pengaturan › Perangkat Desa.
4. **Redaksi 8 surat aktif** dibandingkan dengan surat asli desa.
5. **Satu surat uji per jenis** diterbitkan, dicetak, dan diperiksa Sekretaris Desa.
6. **Prosedur pencadangan kunci PII** dijalankan sebelum data penduduk sungguhan
   masuk (`docs/ops/runbook/pencadangan-kunci.md`).
