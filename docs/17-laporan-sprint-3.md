# 17 — Laporan Sprint 3: Data & Mutasi
**Periode:** Sprint 3 · **Status:** Selesai · **Tanggal:** 2026-10-08

Sasaran (docs/11 §2): impor massal Excel dengan pra-validasi, mutasi penduduk,
laporan anomali, statistik agregat.

---

## 1. Hasil Kerja

| Kemampuan | Rujukan | Keterangan |
|-----------|---------|------------|
| Impor massal Excel/CSV | FR-PDK-11, UC-04 | Dua tahap: pra-validasi lalu eksekusi |
| Template Excel resmi | UC-04 langkah 1 | Dapat diunduh, berisi baris contoh |
| Mutasi penduduk | FR-PDK-10, BR-03 | Lahir, mati, pindah datang, pindah keluar |
| Statistik agregat | FR-PDK-15, ADR-007 | Tabel ringkasan + piramida penduduk |
| Penyamaran sel kecil | ADR-008 | Kelompok < 5 orang tampil "< 5" |
| Laporan anomali | FR-PDK-13, BR-06 | 6 pemeriksaan otomatis |
| Ekspor CSV | FR-PDK-12 | Mengikuti saringan, selalu tercatat |

### Keputusan rancangan yang menentukan

**Impor dipisah menjadi dua tahap.** Berkas diperiksa seluruhnya lebih dulu dan
tidak ada yang disimpan sampai petugas menyetujui hasilnya. Alternatifnya — impor
langsung lalu laporkan yang gagal — meninggalkan basis data setengah jadi: sebagian
warga masuk, sebagian tidak, dan petugas tidak punya cara mengetahui mana yang mana
selain memeriksa satu per satu.

**Wilayah tidak dibuat otomatis saat impor.** Satu salah ketik kode RT akan
melahirkan wilayah hantu — dan wilayah menentukan hak akses Kepala Dusun. Baris
yang kode wilayahnya tidak dikenali tetap masuk tanpa wilayah, lalu muncul di
laporan anomali.

**NIK yang sudah ada diperbarui, bukan ditolak.** Impor ulang adalah kejadian
normal: desa memperbaiki berkas sumbernya lalu mengunggah lagi.

**Penyamaran sel kecil dimatikan untuk pengguna internal berizin laporan.**
Penyamaran ada untuk mencegah identifikasi ulang oleh pihak luar. Petugas desa
sudah berhak melihat data orangnya satu per satu; menyamarkan angka bagi mereka
hanya membuat laporan tidak dapat dipakai.

---

## 2. Status Pengujian

```
Architecture     4 uji
Authorization   29 uji
Unit            36 uji
Feature         96 uji   (+30 dari Sprint 3)
──────────────────────────────────────────
TOTAL          165 uji · 450 asersi · semua lolos
PHPStan level 6 · 0 error    vue-tsc · 0 error    Pint · bersih
```

### Verifikasi ujung-ke-ujung
Berkas CSV 4 baris diunggah lewat antarmuka:

```
Pra-validasi : 4 baris, 2 sah, 2 bermasalah
  baris 4 · NIK           · NIK harus 16 angka, ditemukan 6 angka.
  baris 5 · Tanggal Lahir · Tanggal lahir melebihi hari ini.
Eksekusi     : 2 ditambah, 0 diperbarui, 2 dilewati
Pemetaan     : agama=islam, pekerjaan=wiraswasta,
               wilayah=Krajan / RT 001, KK=3201••••••••0501
```

Nilai teks bebas ("Islam", "Wiraswasta") terpetakan ke kode referensi, dan kartu
keluarga dibuat otomatis dari nomor KK pada berkas.

---

## 3. Temuan

### 3.1 Tabel `resident_mutations` tidak pernah dibuat
Dirancang sejak dokumen 05 §3.2, tetapi migrasinya terlewat pada Sprint 1 dan tidak
ada yang menyadarinya selama dua sprint — karena belum ada kode yang memakainya.
Baru ketahuan saat modul mutasi dibangun.

**Pelajaran:** skema yang tertulis di dokumen belum tentu ada di basis data.
Yang membuktikan keberadaannya hanyalah kode yang memakainya.

### 3.2 Statistik disegarkan sebelum status berubah
`MutationService` memanggil penyegaran statistik di dalam `store()` — yaitu
**sebelum** status penduduk diubah. Akibatnya angka statistik selalu tertinggal
satu langkah: orang yang baru dicatat meninggal tetap terhitung sampai ada mutasi
berikutnya.

Terlihat seperti fitur yang berjalan, dan hanya ketahuan karena ujinya memeriksa
angka sebelum dan sesudah. Diperbaiki dengan memindahkan penyegaran ke setelah
transaksi selesai.

### 3.3 Dua kesalahan pada pengujian itu sendiri
- Uji anomali memanggil service tanpa `actingAs`, sehingga lingkupnya tamu dan
  hasilnya nol. Hasil nol itu **benar** — ujinya yang keliru.
- Uji impor mengambil pekerjaan terbaru dengan `latest('created_at')`, padahal
  kolom itu hanya berpresisi detik. Dua unggahan dalam satu detik membuatnya
  mengambil pekerjaan yang salah.

Keduanya mengingatkan hal yang sama: uji yang gagal belum tentu menunjukkan kode
yang salah.

---

## 4. Belum Dikerjakan

| Item | Alasan | Jadwal |
|------|--------|--------|
| Usulan koreksi data oleh Kepala Dusun (FR-PDK-16) | Perlu alur persetujuan *maker-checker* tersendiri | Sprint 4 |
| Pemulihan penduduk terhapus (antarmuka) | Layanannya sudah ada, antarmukanya belum | Sprint 4 |
| Buku Induk Penduduk & Buku Mutasi (FR-LAP-06) | Format Permendagri 47/2016 perlu diverifikasi ke desa | Sprint 7 |
| Ekspor Excel (.xlsx) | Saat ini CSV. Excel membuka CSV dengan baik; .xlsx ditambahkan bila diminta | — |

---

## 5. Risiko

| Risiko | Sebelum | Sesudah | Alasan |
|--------|---------|---------|--------|
| **R-19** Pencarian tidak toleran salah ketik → data ganda | 9 | **5** | Laporan anomali "nama mirip" kini mendeteksinya, dan pra-validasi impor menolak NIK ganda di dalam satu berkas |
| **R-3** Data awal kotor | 12 | **8** | Impor menolak baris bermasalah dengan sebab yang jelas sebelum apa pun tersimpan |

### Risiko baru
| ID | Risiko | P | D | Skor | Mitigasi |
|----|--------|---|---|------|----------|
| R-20 | Berkas impor tersimpan di server memuat NIK ribuan warga | 2 | 4 | 8 | Disimpan di luar web root dengan nama acak; dihapus saat impor dibatalkan. **Belum ada**: penghapusan otomatis berkas impor yang sudah selesai — perlu *job* pembersih (Sprint 4) |

---

## 6. Siap untuk Sprint 4

Sprint 4 (persuratan: jenis surat, template, formulir dinamis, penomoran) dapat
dimulai. Yang relevan sudah tersedia:
- Data penduduk lengkap beserta mutasi — pemohon surat dapat divalidasi statusnya
  (RULE-03: penduduk meninggal/pindah tidak dapat mengajukan surat).
- Katalog 22 jenis surat siap di-*seed* ([dokumen 13](13-katalog-surat.md)).
- Pola penjadwalan, antrean, dan polling status sudah terbukti lewat modul impor —
  akan dipakai ulang untuk render PDF.

**Yang masih perlu diputuskan sebelum Sprint 5:**
1. **Pola penomoran surat** — ambil dari arsip surat asli desa (13 §3). ⚠
2. **Pejabat penanda tangan** per jenis surat.
