# 13 — Katalog Jenis Surat & Template
**Proyek:** Sistem Informasi Desa (SID) · **Versi:** 1.0 · **Acuan:** KEP-003

> **Cara pakai dokumen ini:** katalog di bawah adalah **isi awal** sistem yang dimuat
> lewat *seeder* saat pemasangan. Setelah terpasang, petugas desa dapat mengubah nama
> layanan, syarat, isi template, SLA, dan pola nomor dari menu **Pengaturan › Jenis
> Surat** tanpa bantuan programmer (FR-SRT-01…03).
>
> **Dua hal yang WAJIB diverifikasi dengan desa sebelum go-live** — keduanya berbeda
> di setiap desa dan tidak boleh diasumsikan:
> 1. **Pola penomoran surat** (§3) — ambil dari contoh surat asli desa.
> 2. **Pejabat penanda tangan** per jenis surat (§4) — Kades saja, atau boleh Sekdes.

---

## 1. Arsitektur Template: Layout Bersama + Isi Per Surat

Kesalahan umum pada sistem semacam ini adalah membuat 20 template lengkap yang
masing-masing menyalin kop, penutup, dan blok tanda tangan. Akibatnya, mengubah kop
desa berarti menyunting 20 berkas.

**Pendekatan yang dipakai:** satu **layout bersama** + satu **isi pokok** per jenis surat.

```
┌─ layout_surat.html  (satu untuk semua, disunting sekali) ──────────┐
│  KOP DESA  (logo, nama desa, alamat, kontak)                       │
│  ─────────────────────────────────────────────────────────────     │
│  JUDUL SURAT          ← {{judul_surat}}                            │
│  Nomor: ...           ← {{nomor_surat}}                            │
│                                                                    │
│  Pembuka baku         ← {{pembuka}}                                │
│  Blok identitas       ← {{blok_identitas}}                         │
│                                                                    │
│  ╔══════════════════════════════════════════════════════════════╗  │
│  ║   >>> ISI POKOK PER JENIS SURAT  ({{isi_surat}}) <<<         ║  │
│  ╚══════════════════════════════════════════════════════════════╝  │
│                                                                    │
│  Keperluan            ← {{keperluan}}                              │
│  Penutup baku                                                      │
│  Tempat, tanggal · Jabatan · [QR] · Nama penanda tangan            │
└────────────────────────────────────────────────────────────────────┘
```

Yang disimpan di `letter_types.template_html` hanyalah **isi pokok** (2–5 paragraf).
Layout, kop, dan blok tanda tangan ada di satu berkas terpisah yang diubah dari
**Pengaturan › Identitas & Kop Surat**.

---

## 2. Daftar Variabel Template

Variabel ditulis `{{nama_variabel}}` dan terisi otomatis dari data penduduk & desa.

### 2.1 Data pemohon (otomatis dari basis data penduduk)
| Variabel | Contoh hasil |
|----------|--------------|
| `{{nama}}` | SITI AMINAH |
| `{{nik}}` | 3201234567890123 |
| `{{nomor_kk}}` | 3201234567890001 |
| `{{tempat_lahir}}` | Sukamaju |
| `{{tanggal_lahir}}` | 17 Maret 1985 |
| `{{tempat_tanggal_lahir}}` | Sukamaju, 17 Maret 1985 |
| `{{umur}}` | 41 |
| `{{jenis_kelamin}}` | Perempuan |
| `{{agama}}` | Islam |
| `{{status_perkawinan}}` | Kawin |
| `{{pekerjaan}}` | Petani |
| `{{pendidikan}}` | SLTP/Sederajat |
| `{{kewarganegaraan}}` | Indonesia |
| `{{alamat}}` | Jl. Melati No. 12 |
| `{{alamat_lengkap}}` | Jl. Melati No. 12, RT 003 RW 002, Dusun Krajan |
| `{{rt}}` · `{{rw}}` · `{{dusun}}` | 003 · 002 · Krajan |
| `{{nama_ayah}}` · `{{nama_ibu}}` | AHMAD HIDAYAT · SUMIYATI |

### 2.2 Data desa (dari Pengaturan › Identitas Desa)
`{{desa}}` · `{{kecamatan}}` · `{{kabupaten}}` · `{{provinsi}}` · `{{kode_desa}}` ·
`{{alamat_kantor}}` · `{{kode_pos}}` · `{{telepon_desa}}` · `{{email_desa}}` ·
`{{website_desa}}`

### 2.3 Data surat
`{{nomor_surat}}` · `{{judul_surat}}` · `{{tanggal_terbit}}` (04-10-2026) ·
`{{tanggal_terbit_panjang}}` (4 Oktober 2026) · `{{tempat_tanggal_terbit}}`
(Sukamaju, 4 Oktober 2026) · `{{keperluan}}` · `{{masa_berlaku}}` ·
`{{kode_verifikasi}}` · `{{qr_verifikasi}}`

### 2.4 Penanda tangan
`{{penandatangan_nama}}` · `{{penandatangan_jabatan}}` · `{{penandatangan_nip}}`

### 2.5 Field formulir dinamis
Setiap *field* yang didefinisikan di `form_schema` dapat dipanggil dengan prefiks
`form.`, misalnya `{{form.nama_usaha}}`, `{{form.penghasilan_per_bulan}}`.

### 2.6 Penolong (helper)
| Penulisan | Hasil |
|-----------|-------|
| `{{#upper}}{{nama}}{{/upper}}` | SITI AMINAH |
| `{{#rupiah}}{{form.penghasilan}}{{/rupiah}}` | Rp 2.500.000 |
| `{{#angka_huruf}}{{form.penghasilan}}{{/angka_huruf}}` | dua juta lima ratus ribu rupiah |
| `{{#tanggal_panjang}}{{form.tanggal_kejadian}}{{/tanggal_panjang}}` | 1 Oktober 2026 |

> **Catatan implementasi:** variabel yang tidak dikenal **tidak** boleh dibiarkan
> tercetak sebagai `{{xxx}}` di surat resmi. Editor template menolak simpan bila ada
> variabel tak dikenal (FR-SRT-02), dan *renderer* mengosongkannya sebagai jaring pengaman.

---

## 3. Pola Penomoran Surat

Pola bersifat *configurable* per jenis surat (FR-SRT-08). Tiga pola yang paling umum:

| Pola | Hasil | Catatan |
|------|-------|---------|
| **A. Urut sederhana** (bawaan) | `145/SKTM/DS.2001/X/2026` | Paling umum di desa kecil |
| **B. Dengan kode klasifikasi arsip** | `470/145/DS.2001/X/2026` | Dipakai desa yang mengikuti kode klasifikasi arsip |
| **C. Urut tunggal semua surat** | `145/DS.2001/X/2026` | Satu deret untuk semua jenis surat |

**Token yang tersedia:**
| Token | Hasil |
|-------|-------|
| `{urut}` | 145 |
| `{urut:4}` | 0145 (dengan *padding* nol) |
| `{kode}` | SKTM (kode jenis surat) |
| `{klasifikasi}` | 470 (kode klasifikasi arsip jenis surat) |
| `{kode_desa}` | DS.2001 |
| `{bulan}` · `{bulan_romawi}` | 10 · X |
| `{tahun}` · `{tahun2}` | 2026 · 26 |

**Perbedaan pola B dan C bukan kosmetik — berpengaruh pada penghitung:**
- Pola A & B: penghitung **per jenis surat per tahun** (SKTM punya deret sendiri).
- Pola C: penghitung **tunggal per tahun** (semua jenis surat berbagi satu deret).

Pilihan ini diatur di **Pengaturan › Penomoran Surat** dan **harus** disamakan dengan
praktik desa sebelum surat pertama terbit — mengubahnya setelah ada surat terbit akan
menimbulkan deret yang tidak konsisten.

> **⚠ Kode klasifikasi arsip (`{klasifikasi}`):** kolom ini disediakan dan dapat diisi
> per jenis surat, tetapi **nilai bawaan kami kosongkan**. Kode klasifikasi berbeda
> antar daerah dan antar versi peraturan kearsipan. **Ambil kodenya dari arsip surat
> desa yang sudah ada**, atau tanyakan ke Sekretaris Desa / Bagian Hukum kabupaten.
> Jangan menebak — kode yang salah akan terbawa ke seluruh surat selama bertahun-tahun.

Nomor terbit **hanya** saat surat disetujui, tidak saat diajukan (RULE-04, ADR-001).

---

## 4. Alur Persetujuan Bawaan

Tiga pola alur yang disediakan, dipilih per jenis surat:

| Alur | Tahapan | Dipakai untuk |
|------|---------|---------------|
| **F1 — Satu tahap** | Operator buat → **Kades** setujui | Surat rutin sederhana |
| **F2 — Dua tahap** (bawaan) | Operator buat → **Kasi terkait** verifikasi → **Kades** setujui | Mayoritas surat |
| **F3 — Tiga tahap** | Operator buat → **Kasi** verifikasi → **Sekdes** periksa → **Kades** setujui | Surat berisiko (waris, tanah, penghasilan) |

**Penanda tangan bawaan: Kepala Desa** untuk seluruh jenis surat. Bila desa
mengizinkan Sekretaris Desa menandatangani surat tertentu (praktik umum saat Kades
berhalangan), atur di **Pengaturan › Pejabat Penanda Tangan** — termasuk aturan
"a.n. Kepala Desa" bila diperlukan.

---

## 5. Delapan Surat Prioritas (Rinci)

Delapan jenis ini mencakup mayoritas permintaan harian di kantor desa dan dibangun
lengkap pada Sprint 4–5.

---

### 5.1 SKTM — Surat Keterangan Tidak Mampu
| Atribut | Nilai |
|---------|-------|
| Kode | `SKTM` |
| SLA | 1 hari kerja |
| Biaya | Rp 0 |
| Alur | F2 (Kasi Kesejahteraan → Kades) |
| Dapat diajukan daring | Ya |
| Masa berlaku | 3 bulan |

**Syarat:**
| Syarat | Wajib |
|--------|-------|
| Fotokopi Kartu Keluarga | ✓ |
| Fotokopi KTP pemohon | ✓ |
| Surat pengantar RT/RW | ✓ |
| Dokumen pendukung keperluan (mis. surat dari sekolah/rumah sakit) | — |

**Field formulir tambahan:**
| Field | Tipe | Wajib | Keterangan |
|-------|------|-------|-----------|
| `keperluan` | pilihan + teks lain | ✓ | Beasiswa / Keringanan biaya sekolah / Keringanan biaya rumah sakit / Bantuan sosial / Lainnya |
| `keterangan_tambahan` | teks panjang | — | Mis. nama sekolah & kelas anak |
| `penghasilan_per_bulan` | angka (rupiah) | — | Bila diminta instansi tujuan |

**Isi pokok template:**
```html
<p>Orang tersebut di atas adalah benar penduduk Desa {{desa}}, Kecamatan
{{kecamatan}}, Kabupaten {{kabupaten}}, dan menurut sepengetahuan kami
yang bersangkutan termasuk <strong>keluarga yang tidak mampu</strong>
secara ekonomi.</p>

<p>Surat keterangan ini dibuat untuk keperluan:
<strong>{{#upper}}{{keperluan}}{{/upper}}</strong>
{{#form.keterangan_tambahan}} ({{form.keterangan_tambahan}}){{/form.keterangan_tambahan}}.</p>
```
**Catatan kebijakan:** jangan menerbitkan SKTM otomatis berdasarkan kolom
`is_poor` di basis data. Status ekonomi berubah, dan penerbitan otomatis membuka
celah penyalahgunaan. Tetap harus ada verifikasi petugas.

---

### 5.2 SKD — Surat Keterangan Domisili
| Atribut | Nilai |
|---------|-------|
| Kode | `SKD` · **SLA** 1 hari kerja · **Biaya** Rp 0 · **Alur** F2 (Kasi Pemerintahan → Kades) · **Daring** Ya · **Masa berlaku** 6 bulan |

**Syarat:** Fotokopi KK ✓ · Fotokopi KTP ✓ · Surat pengantar RT/RW ✓

**Field tambahan:**
| Field | Tipe | Wajib |
|-------|------|-------|
| `keperluan` | teks | ✓ |
| `alamat_domisili` | teks | — (bila berbeda dari alamat KK) |
| `lama_tinggal` | teks | — (mis. "sejak tahun 2015") |

**Isi pokok:**
```html
<p>Orang tersebut di atas adalah benar penduduk yang berdomisili / bertempat
tinggal di <strong>{{#form.alamat_domisili}}{{form.alamat_domisili}}{{/form.alamat_domisili}}
{{^form.alamat_domisili}}{{alamat_lengkap}}{{/form.alamat_domisili}}</strong>,
Desa {{desa}}, Kecamatan {{kecamatan}}, Kabupaten {{kabupaten}}
{{#form.lama_tinggal}} {{form.lama_tinggal}}{{/form.lama_tinggal}}.</p>
```

---

### 5.3 SKU — Surat Keterangan Usaha
| Atribut | Nilai |
|---------|-------|
| Kode | `SKU` · **SLA** 2 hari kerja · **Biaya** Rp 0 · **Alur** F2 (Kasi Pelayanan → Kades) · **Daring** Ya · **Masa berlaku** 1 tahun |

**Syarat:** Fotokopi KK ✓ · Fotokopi KTP ✓ · Surat pengantar RT/RW ✓ · Foto tempat usaha —

**Field tambahan:**
| Field | Tipe | Wajib |
|-------|------|-------|
| `nama_usaha` | teks | ✓ |
| `jenis_usaha` | teks | ✓ |
| `alamat_usaha` | teks | ✓ |
| `tahun_mulai_usaha` | angka (tahun) | ✓ |
| `jumlah_pekerja` | angka | — |
| `penghasilan_per_bulan` | angka (rupiah) | — |
| `keperluan` | pilihan | ✓ (Pengajuan kredit bank / Perizinan usaha / NIB-IUMK / Lainnya) |

**Isi pokok:**
```html
<p>Orang tersebut di atas adalah benar penduduk Desa {{desa}} dan menurut
sepengetahuan kami yang bersangkutan memiliki usaha sebagai berikut:</p>

<table>
  <tr><td>Nama Usaha</td><td>: {{form.nama_usaha}}</td></tr>
  <tr><td>Jenis Usaha</td><td>: {{form.jenis_usaha}}</td></tr>
  <tr><td>Alamat Usaha</td><td>: {{form.alamat_usaha}}</td></tr>
  <tr><td>Mulai Berusaha</td><td>: Tahun {{form.tahun_mulai_usaha}}</td></tr>
</table>

<p>Usaha tersebut benar adanya dan sampai saat surat ini diterbitkan
masih berjalan.</p>
```

---

### 5.4 SKL — Surat Keterangan Kelahiran
| Atribut | Nilai |
|---------|-------|
| Kode | `SKL` · **SLA** 1 hari kerja · **Biaya** Rp 0 · **Alur** F2 (Kasi Pemerintahan → Kades) · **Daring** Ya |

**Syarat:** Surat keterangan lahir dari bidan/rumah sakit ✓ · Fotokopi KK ✓ ·
Fotokopi KTP ayah & ibu ✓ · Fotokopi buku nikah / akta perkawinan ✓ ·
Surat pengantar RT/RW ✓

**Field tambahan:**
| Field | Tipe | Wajib |
|-------|------|-------|
| `nama_anak` | teks | ✓ |
| `jenis_kelamin_anak` | pilihan (L/P) | ✓ |
| `tempat_lahir_anak` | teks | ✓ |
| `tanggal_lahir_anak` | tanggal | ✓ |
| `waktu_lahir` | waktu | — |
| `anak_ke` | angka | ✓ |
| `berat_badan` | angka (kg) | — |
| `panjang_badan` | angka (cm) | — |
| `penolong_kelahiran` | pilihan | ✓ (Dokter / Bidan / Dukun bayi / Lainnya) |
| `nama_ayah` · `nik_ayah` | teks | ✓ |
| `nama_ibu` · `nik_ibu` | teks | ✓ |

**Isi pokok:**
```html
<p>Dengan ini menerangkan bahwa telah lahir seorang anak:</p>
<table>
  <tr><td>Nama</td><td>: {{#upper}}{{form.nama_anak}}{{/upper}}</td></tr>
  <tr><td>Jenis Kelamin</td><td>: {{form.jenis_kelamin_anak}}</td></tr>
  <tr><td>Tempat/Tgl Lahir</td><td>: {{form.tempat_lahir_anak}},
      {{#tanggal_panjang}}{{form.tanggal_lahir_anak}}{{/tanggal_panjang}}</td></tr>
  <tr><td>Anak Ke-</td><td>: {{form.anak_ke}}</td></tr>
  <tr><td>Penolong Kelahiran</td><td>: {{form.penolong_kelahiran}}</td></tr>
  <tr><td>Nama Ayah</td><td>: {{#upper}}{{form.nama_ayah}}{{/upper}} (NIK {{form.nik_ayah}})</td></tr>
  <tr><td>Nama Ibu</td><td>: {{#upper}}{{form.nama_ibu}}{{/upper}} (NIK {{form.nik_ibu}})</td></tr>
</table>
<p>Surat keterangan ini dibuat untuk keperluan pengurusan
<strong>Akta Kelahiran</strong> pada Dinas Kependudukan dan Pencatatan Sipil
Kabupaten {{kabupaten}}.</p>
```
**Integrasi modul:** saat surat ini disetujui, sistem **menawarkan** (tidak otomatis
melakukan) pencatatan mutasi **Kelahiran** dan penambahan anak ke KK pemohon —
satu klik, data sudah terisi dari formulir. Ini mencegah data lahir tercatat di surat
tetapi tidak masuk basis data penduduk, yang merupakan sumber selisih data paling
umum di desa.

---

### 5.5 SKM — Surat Keterangan Kematian
| Atribut | Nilai |
|---------|-------|
| Kode | `SKM` · **SLA** 1 hari kerja (**prioritas**, layani segera) · **Biaya** Rp 0 · **Alur** F2 (Kasi Pemerintahan → Kades) · **Daring** Ya |

**Syarat:** Fotokopi KK ✓ · Fotokopi KTP almarhum/almarhumah ✓ ·
Fotokopi KTP pelapor ✓ · Surat keterangan kematian dari rumah sakit/dokter — ·
Surat pengantar RT/RW ✓

**Field tambahan:**
| Field | Tipe | Wajib |
|-------|------|-------|
| `resident_meninggal_id` | pencarian penduduk | ✓ |
| `tanggal_kematian` | tanggal | ✓ |
| `waktu_kematian` | waktu | — |
| `tempat_kematian` | teks | ✓ |
| `sebab_kematian` | pilihan | ✓ (Sakit / Kecelakaan / Usia lanjut / Lainnya) |
| `hubungan_pelapor` | teks | ✓ |

**Isi pokok:**
```html
<p>Dengan ini menerangkan bahwa:</p>
<table>
  <tr><td>Nama</td><td>: {{#upper}}{{meninggal.nama}}{{/upper}}</td></tr>
  <tr><td>NIK</td><td>: {{meninggal.nik}}</td></tr>
  <tr><td>Tempat/Tgl Lahir</td><td>: {{meninggal.tempat_tanggal_lahir}}</td></tr>
  <tr><td>Alamat</td><td>: {{meninggal.alamat_lengkap}}</td></tr>
</table>
<p>Telah meninggal dunia pada
<strong>{{#tanggal_panjang}}{{form.tanggal_kematian}}{{/tanggal_panjang}}</strong>
di {{form.tempat_kematian}}, dengan sebab {{form.sebab_kematian}}.</p>
<p>Surat keterangan ini dibuat untuk keperluan pengurusan
<strong>Akta Kematian</strong> dan keperluan lain yang sah.</p>
```
**Integrasi modul:** saat disetujui, sistem **menawarkan** pencatatan mutasi
**Kematian** → status penduduk menjadi `meninggal`, keluar dari statistik (RULE-03),
dan tidak dapat lagi menjadi pemohon surat. Bila almarhum adalah **kepala keluarga**,
sistem mengingatkan bahwa KK perlu kepala keluarga baru (RULE-02).

---

### 5.6 SKP — Surat Keterangan Pindah
| Atribut | Nilai |
|---------|-------|
| Kode | `SKP` · **SLA** 2 hari kerja · **Biaya** Rp 0 · **Alur** F3 (Kasi → Sekdes → Kades) · **Daring** Ya |

**Syarat:** Fotokopi KK ✓ · Fotokopi KTP ✓ · Surat pengantar RT/RW ✓ ·
KK asli (diserahkan ke Dukcapil) ✓

**Field tambahan:**
| Field | Tipe | Wajib |
|-------|------|-------|
| `jenis_pindah` | pilihan | ✓ (Pindah keluar / Pindah datang) |
| `alamat_tujuan` | teks | ✓ |
| `desa_tujuan` · `kecamatan_tujuan` · `kabupaten_tujuan` · `provinsi_tujuan` | teks | ✓ |
| `alasan_pindah` | pilihan | ✓ (Pekerjaan / Pendidikan / Perkawinan / Keamanan / Kesehatan / Perumahan / Lainnya) |
| `tanggal_rencana_pindah` | tanggal | ✓ |
| `anggota_yang_pindah` | pilihan ganda (anggota KK) | ✓ |
| `status_kk_ditinggalkan` | pilihan | ✓ (Tidak ada yang tinggal / Masih ada anggota) |

**Isi pokok:**
```html
<p>Orang tersebut di atas beserta anggota keluarga sebagaimana tercantum di
bawah ini akan <strong>pindah</strong> ke:</p>
<table>
  <tr><td>Alamat Tujuan</td><td>: {{form.alamat_tujuan}}</td></tr>
  <tr><td>Desa/Kelurahan</td><td>: {{form.desa_tujuan}}</td></tr>
  <tr><td>Kecamatan</td><td>: {{form.kecamatan_tujuan}}</td></tr>
  <tr><td>Kabupaten/Kota</td><td>: {{form.kabupaten_tujuan}}</td></tr>
  <tr><td>Provinsi</td><td>: {{form.provinsi_tujuan}}</td></tr>
  <tr><td>Alasan Pindah</td><td>: {{form.alasan_pindah}}</td></tr>
  <tr><td>Rencana Pindah</td><td>: {{#tanggal_panjang}}{{form.tanggal_rencana_pindah}}{{/tanggal_panjang}}</td></tr>
</table>
{{#tabel_anggota_pindah}}
<p>Surat keterangan ini dibuat untuk keperluan pengurusan Surat Keterangan
Pindah (SKPWNI) pada Dinas Kependudukan dan Pencatatan Sipil.</p>
```
**Integrasi modul:** mutasi **Pindah Keluar** hanya dicatat setelah ada konfirmasi
kepindahan benar terjadi — **bukan** saat surat terbit. Alasannya: banyak warga mengurus
surat pindah lalu tidak pindah. Sistem membuat **pengingat tindak lanjut** 30 hari.

---

### 5.7 SKBM — Surat Keterangan Belum Menikah
| Atribut | Nilai |
|---------|-------|
| Kode | `SKBM` · **SLA** 1 hari kerja · **Biaya** Rp 0 · **Alur** F2 (Kasi Pemerintahan → Kades) · **Daring** Ya · **Masa berlaku** 3 bulan |

**Syarat:** Fotokopi KK ✓ · Fotokopi KTP ✓ · Surat pengantar RT/RW ✓

**Field tambahan:** `keperluan` (teks, ✓) · `nama_saksi_1`, `nama_saksi_2`
(teks, opsional — sebagian instansi memintanya)

**Isi pokok:**
```html
<p>Orang tersebut di atas adalah benar penduduk Desa {{desa}} dan menurut
data administrasi kependudukan serta sepengetahuan kami yang bersangkutan
<strong>belum pernah menikah</strong> (masih berstatus {{status_perkawinan}}).</p>
```
**Validasi otomatis:** bila status perkawinan penduduk di basis data **bukan**
"Belum Kawin", sistem memblokir pengajuan dan menampilkan: *"Status perkawinan
{{nama}} tercatat sebagai Kawin. Perbaiki data kependudukan terlebih dahulu bila
tidak sesuai."* Ini mencegah surat yang bertentangan dengan data desa sendiri.

**Varian terkait** (jenis surat terpisah, template serupa):
- `SKSP` — Surat Keterangan Status Perkawinan (menerangkan status apa pun sesuai data)
- `SKJD` — Surat Keterangan Janda/Duda (syarat tambahan: akta kematian pasangan
  atau akta cerai)

---

### 5.8 SPSKCK — Surat Pengantar SKCK
| Atribut | Nilai |
|---------|-------|
| Kode | `SPSKCK` · **SLA** 1 hari kerja · **Biaya** Rp 0 · **Alur** F2 (Kasi Pemerintahan → Kades) · **Daring** Ya · **Masa berlaku** 1 bulan |

**Syarat:** Fotokopi KK ✓ · Fotokopi KTP ✓ · Surat pengantar RT/RW ✓ ·
Foto 4×6 (2 lembar) —

**Field tambahan:** `keperluan` (pilihan: Melamar pekerjaan / Pendaftaran
CPNS-TNI-Polri / Pendaftaran sekolah / Pengurusan visa / Lainnya, ✓)

**Isi pokok:**
```html
<p>Orang tersebut di atas adalah benar penduduk Desa {{desa}} dan menurut
sepengetahuan kami yang bersangkutan <strong>berkelakuan baik</strong>, tidak
pernah terlibat perkara pidana maupun perbuatan yang melanggar hukum serta
tidak pernah tersangkut organisasi terlarang.</p>

<p>Surat pengantar ini diberikan untuk keperluan pengurusan
<strong>Surat Keterangan Catatan Kepolisian (SKCK)</strong> pada Kepolisian
Sektor {{kecamatan}} / Kepolisian Resor {{kabupaten}}, guna
{{#upper}}{{keperluan}}{{/upper}}.</p>
```
**Catatan kebijakan:** klausa "berkelakuan baik" adalah keterangan menurut
sepengetahuan pemerintah desa, **bukan** hasil pemeriksaan catatan kriminal. Wewenang
itu ada di kepolisian. Redaksi di atas sudah membatasi diri pada "menurut
sepengetahuan kami" — **jangan** diubah menjadi pernyataan mutlak.

---

## 6. Dua Belas Surat Lanjutan

Disediakan di *seeder* dengan status **nonaktif**; petugas mengaktifkan yang relevan
dari menu Jenis Surat. Semua memakai layout & variabel yang sama.

| Kode | Nama | SLA | Alur | Field tambahan utama | Syarat khusus |
|------|------|-----|------|----------------------|---------------|
| `SKAW` | Surat Keterangan Ahli Waris | 5 hr | **F3** | daftar ahli waris (nama, NIK, hubungan), nama pewaris, tanggal wafat, harta yang diwariskan | Akta kematian pewaris, KK, KTP semua ahli waris, **2 saksi**, surat pernyataan ahli waris |
| `SKPH` | Surat Keterangan Penghasilan | 2 hr | **F3** | pekerjaan, penghasilan/bln, sumber penghasilan, keperluan | Pengantar RT/RW; bila pegawai: slip gaji |
| `SKHL` | Surat Keterangan Kehilangan | 1 hr | F2 | jenis barang/dokumen, ciri, perkiraan waktu & tempat hilang | Fotokopi dokumen yang hilang (bila ada) |
| `SKTT` | Surat Keterangan Tempat Tinggal (pendatang) | 2 hr | F2 | alamat tinggal, lama tinggal, pemilik rumah, pekerjaan di desa | KTP daerah asal, surat pengantar daerah asal, pengantar RT/RW |
| `SPN` | Surat Pengantar Nikah | 2 hr | **F3** | data calon pasangan, status, nama orang tua kedua pihak, rencana tanggal & tempat akad | KK, KTP, akta lahir, pas foto, akta cerai/kematian bila pernah menikah, izin orang tua bila di bawah umur |
| `SKU-IUMK` | Surat Keterangan Usaha untuk IUMK/NIB | 2 hr | F2 | nama & jenis usaha, alamat, modal, jumlah pekerja, NIB bila ada | Sama dengan SKU + foto tempat usaha |
| `SIK` | Surat Izin/Rekomendasi Keramaian | 3 hr | **F3** | jenis kegiatan, tanggal & waktu, lokasi, perkiraan jumlah peserta, penanggung jawab | Pengantar RT/RW, susunan acara, persetujuan lingkungan |
| `SKPT` | Surat Keterangan Penguasaan Fisik Bidang Tanah | 7 hr | **F3** | letak & batas tanah (4 sisi), luas, status, riwayat perolehan, nama pemilik sebelumnya | Bukti perolehan, **2 saksi batas**, pengantar RT/RW, SPPT PBB, **pengecekan lapangan oleh Kasi** |
| `SKBI` | Surat Keterangan Beda Identitas | 2 hr | F2 | data yang berbeda, nilai di dokumen A dan B, dokumen yang dianggap benar | Fotokopi kedua dokumen yang berbeda |
| `SKBK` | Surat Keterangan Belum Memiliki Rumah / Tempat Tinggal Tetap | 2 hr | F2 | keperluan, keterangan tempat tinggal saat ini | KK, KTP, pengantar RT/RW |
| `SRK` | Surat Rekomendasi / Pengantar Umum | 1 hr | F2 | ditujukan kepada, hal, isi rekomendasi (teks bebas) | Pengantar RT/RW |
| `SKBB` | Surat Keterangan Bersih Diri / Tidak Terlibat Narkoba | 2 hr | F2 | keperluan, instansi tujuan | KK, KTP, pengantar RT/RW |

**Tiga surat yang menuntut kehati-hatian ekstra** — alurnya sengaja F3 (tiga tahap)
dan tidak disarankan diaktifkan untuk pengajuan daring:

| Surat | Mengapa berisiko |
|-------|------------------|
| `SKAW` Ahli Waris | Berimplikasi hukum keperdataan atas harta. Kesalahan daftar ahli waris memicu sengketa. Wajib ada saksi dan pemeriksaan berkas fisik |
| `SKPT` Penguasaan Tanah | Sumber sengketa tanah paling umum. **Wajib pengecekan lapangan dan saksi batas** — tidak boleh terbit hanya dari berkas |
| `SKPH` Penghasilan | Dipakai untuk pengajuan kredit. Angka yang dilebihkan merugikan warga sendiri bila gagal bayar |

Untuk ketiga surat ini, sistem menampilkan **peringatan di layar persetujuan** yang
mengingatkan verifikator pada syarat tersebut sebelum tombol Setujui dapat ditekan.

---

## 7. Ringkasan Katalog

| # | Kode | Nama | SLA | Alur | Daring | Status awal |
|---|------|------|-----|------|--------|-------------|
| 1 | SKTM | Surat Keterangan Tidak Mampu | 1 hr | F2 | ✓ | **Aktif** |
| 2 | SKD | Surat Keterangan Domisili | 1 hr | F2 | ✓ | **Aktif** |
| 3 | SKU | Surat Keterangan Usaha | 2 hr | F2 | ✓ | **Aktif** |
| 4 | SKL | Surat Keterangan Kelahiran | 1 hr | F2 | ✓ | **Aktif** |
| 5 | SKM | Surat Keterangan Kematian | 1 hr | F2 | ✓ | **Aktif** |
| 6 | SKP | Surat Keterangan Pindah | 2 hr | F3 | ✓ | **Aktif** |
| 7 | SKBM | Surat Keterangan Belum Menikah | 1 hr | F2 | ✓ | **Aktif** |
| 8 | SPSKCK | Surat Pengantar SKCK | 1 hr | F2 | ✓ | **Aktif** |
| 9 | SKSP | Surat Keterangan Status Perkawinan | 1 hr | F2 | ✓ | Nonaktif |
| 10 | SKJD | Surat Keterangan Janda/Duda | 1 hr | F2 | ✓ | Nonaktif |
| 11 | SKAW | Surat Keterangan Ahli Waris | 5 hr | F3 | ✗ | Nonaktif |
| 12 | SKPH | Surat Keterangan Penghasilan | 2 hr | F3 | ✓ | Nonaktif |
| 13 | SKHL | Surat Keterangan Kehilangan | 1 hr | F2 | ✓ | Nonaktif |
| 14 | SKTT | Surat Keterangan Tempat Tinggal | 2 hr | F2 | ✓ | Nonaktif |
| 15 | SPN | Surat Pengantar Nikah | 2 hr | F3 | ✗ | Nonaktif |
| 16 | SKU-IUMK | SKU untuk IUMK/NIB | 2 hr | F2 | ✓ | Nonaktif |
| 17 | SIK | Surat Izin/Rekomendasi Keramaian | 3 hr | F3 | ✓ | Nonaktif |
| 18 | SKPT | SK Penguasaan Fisik Bidang Tanah | 7 hr | F3 | ✗ | Nonaktif |
| 19 | SKBI | Surat Keterangan Beda Identitas | 2 hr | F2 | ✓ | Nonaktif |
| 20 | SKBK | SK Belum Memiliki Rumah | 2 hr | F2 | ✓ | Nonaktif |
| 21 | SRK | Surat Rekomendasi / Pengantar Umum | 1 hr | F2 | ✗ | Nonaktif |
| 22 | SKBB | Surat Keterangan Bersih Diri | 2 hr | F2 | ✓ | Nonaktif |

**8 aktif saat pemasangan · 14 tersedia untuk diaktifkan.**

---

## 8. Contoh Data Seeder

Bentuk satu entri (ringkas, untuk `database/seeders/LetterTypeSeeder.php`):

```json
{
  "code": "SKTM",
  "name": "Surat Keterangan Tidak Mampu",
  "title_on_letter": "SURAT KETERANGAN TIDAK MAMPU",
  "classification_code": null,
  "description": "Keterangan bahwa warga termasuk keluarga tidak mampu secara ekonomi, untuk keperluan beasiswa, keringanan biaya sekolah/rumah sakit, atau pengajuan bantuan.",
  "sla_days": 1,
  "fee": 0,
  "validity_months": 3,
  "is_public": true,
  "is_active": true,
  "sort_order": 1,
  "number_pattern": "{urut}/{kode}/{kode_desa}/{bulan_romawi}/{tahun}",
  "approval_flow": [
    { "step": 1, "action": "verify",  "role": "kasi_kesejahteraan" },
    { "step": 2, "action": "approve", "role": "kepala_desa" }
  ],
  "requirements": [
    { "label": "Fotokopi Kartu Keluarga", "type": "file", "required": true },
    { "label": "Fotokopi KTP pemohon",    "type": "file", "required": true },
    { "label": "Surat pengantar RT/RW",   "type": "file", "required": true },
    { "label": "Dokumen pendukung keperluan", "type": "file", "required": false }
  ],
  "form_schema": {
    "fields": [
      { "name": "keperluan", "label": "Keperluan", "type": "select", "required": true,
        "options": ["Pengajuan beasiswa", "Keringanan biaya sekolah",
                    "Keringanan biaya rumah sakit", "Pengajuan bantuan sosial", "Lainnya"],
        "allow_other": true },
      { "name": "keterangan_tambahan", "label": "Keterangan tambahan",
        "type": "textarea", "required": false, "max": 500,
        "hint": "Contoh: untuk anak bernama ... kelas ... di SMAN ..." },
      { "name": "penghasilan_per_bulan", "label": "Penghasilan per bulan",
        "type": "currency", "required": false }
    ]
  },
  "template_html": "<p>Orang tersebut di atas adalah benar penduduk Desa {{desa}} ...</p>"
}
```

**Yang wajib dilakukan saat pemasangan di desa nyata:**
1. Jalankan *seeder* → 22 jenis surat masuk (8 aktif).
2. Isi **Pengaturan › Identitas Desa** (nama, alamat, logo, kop).
3. Isi **Pengaturan › Pejabat Penanda Tangan**.
4. Sesuaikan **pola penomoran** dengan contoh surat asli desa (§3). ⚠
5. Bandingkan template 8 surat aktif dengan surat asli desa; sesuaikan redaksi.
6. Terbitkan **1 surat uji** per jenis, cetak, dan **minta Sekdes memeriksa** sebelum
   layanan dibuka ke warga.

Langkah 6 adalah pengaman terakhir — jauh lebih murah menemukan kesalahan redaksi
pada surat uji daripada pada surat yang sudah diterima instansi lain.
