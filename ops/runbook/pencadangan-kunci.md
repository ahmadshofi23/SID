# Runbook — Pencadangan Kunci Data Pribadi

**Dasar:** KEP-006 · **Menutup risiko:** R-18
**Untuk:** Kepala Desa, Sekretaris Desa, dan admin teknis
**Waktu yang dibutuhkan:** ± 20 menit, sekali saja

---

## Mengapa ini penting

NIK dan nomor Kartu Keluarga warga tidak disimpan apa adanya di dalam sistem.
Semuanya diacak lebih dulu, supaya jika suatu saat basis data bocor atau dicuri,
isinya tidak langsung terbaca orang lain.

Pengacakan itu memakai dua baris kode acak yang disebut **kunci**:

| Nama | Fungsinya | Kalau hilang |
|------|-----------|--------------|
| `PII_ENCRYPTION_KEY` | Membuka kembali NIK & nomor KK | **Seluruh NIK dan nomor KK tidak dapat dibaca lagi — selamanya** |
| `PII_HASH_PEPPER` | Mencari NIK & menjaga agar tidak ganda | Pencarian NIK rusak; perlu pengolahan ulang seluruh data |

> Tidak ada cara memulihkan kunci yang hilang. Bukan karena belum ditemukan
> caranya, melainkan karena memang tidak ada — itulah yang membuat enkripsinya
> berguna.

**Kapan wajib dilakukan:** sebelum baris data warga **asli** yang pertama
dimasukkan. Selama isinya masih data contoh, kunci hilang tidak berakibat apa-apa.

---

## Siapa yang memegang

Sesuai KEP-006: **dua orang**, di **dua lokasi fisik berbeda**.

| | Pemegang | Lokasi penyimpanan |
|---|----------|--------------------|
| Salinan 1 | ................................ | ................................ |
| Salinan 2 | ................................ | ................................ |

*(isi saat prosedur dijalankan)*

Dua salinan agar satu orang berhalangan tidak menghentikan segalanya. Dua lokasi
agar satu kebakaran tidak menghabiskan keduanya.

---

## Tiga aturan yang tidak boleh dilanggar

1. **Jangan disimpan bersama cadangan basis data.** Jika pencuri mendapat
   cadangan dan kuncinya sekaligus, enkripsinya sama sekali tidak berguna —
   seperti menempelkan kunci rumah di pintu depan.

2. **Jangan dikirim lewat WhatsApp, email, atau difoto.** Riwayat percakapan,
   galeri ponsel, dan cadangan otomatis ponsel adalah sumber kebocoran paling
   umum. Serahkan kertasnya langsung, dari tangan ke tangan.

3. **Jangan diketik ke dokumen di komputer.** Termasuk Word, Excel, atau catatan
   di ponsel. Tulis tangan, atau cetak lalu hapus berkasnya.

---

## Prosedur

### Langkah 1 — Siapkan formulir

Cetak atau salin tulis formulir di bagian akhir dokumen ini, **dua lembar**.

### Langkah 2 — Baca kunci dari server

Admin teknis membuka berkas `.env` di server:

```bash
grep -E "^PII_(ENCRYPTION_KEY|HASH_PEPPER)=" .env
```

Dua baris akan muncul. Jangan tangkap layar, jangan salin ke mana pun.

### Langkah 3 — Tulis di kertas

Salin kedua nilai ke formulir, **huruf per huruf**. Tulis dengan jelas dan
perhatikan karakter yang mudah tertukar:

```
angka 0  dengan  huruf O         huruf l kecil  dengan  angka 1
huruf I besar  dengan  l kecil   tanda +  dengan  /      akhiran =
```

Sertakan awalan `base64:` pada baris kunci enkripsi.

### Langkah 4 — Tulis sidik jari

Jalankan:

```bash
php artisan sid:pii-key
```

Perintah ini menampilkan dua **sidik jari** — ringkasan pendek dari kunci.
Sidik jari aman dilihat orang lain karena tidak dapat dikembalikan menjadi
kunci aslinya.

Tulis keduanya di formulir. Gunanya: kapan pun Anda ingin memastikan kertas itu
masih cocok dengan yang dipakai server, cukup bandingkan sidik jarinya — tanpa
perlu membuka amplop atau membaca kuncinya.

### Langkah 5 — **Verifikasi salinan** (jangan dilewati)

```bash
php artisan sid:pii-key --verify
```

Ketik ulang kedua nilai **dari kertas**, bukan dari layar. Yang Anda ketik tidak
akan tampil.

- **Cocok** → lanjut ke langkah 6.
- **Tidak cocok** → tulis ulang kertasnya, ulangi langkah ini.
  **Jangan menyimpan kertas yang belum terverifikasi.**

Langkah ini ada karena kesalahan penyalinan satu karakter tidak akan ketahuan
sampai kunci itu benar-benar dibutuhkan — yaitu justru saat keadaan darurat.

### Langkah 6 — Simpan

Masukkan tiap lembar ke amplop tertutup, tulis di luar amplop:

```
KUNCI SISTEM INFORMASI DESA — JANGAN DIBUKA TANPA PERINTAH KEPALA DESA
Dibuat: ....../....../20......    Sidik jari: ......-......-......-......
```

Simpan di dua lokasi yang sudah disepakati. Catat di buku administrasi desa
bahwa kedua salinan telah dibuat dan diverifikasi — **tanpa mencatat kuncinya**.

---

## Pemeriksaan berkala

Setiap **6 bulan**, atau setiap ada pergantian perangkat desa:

```bash
php artisan sid:pii-key
```

Bandingkan sidik jari di layar dengan yang tertulis di luar amplop.

| Hasil | Artinya | Tindakan |
|-------|---------|----------|
| Sama | Cadangan masih sesuai | Tidak ada |
| Berbeda | Kunci di server pernah diganti | **Segera** ulangi prosedur dari Langkah 1 |

Sekaligus pastikan kedua amplop masih ada dan utuh.

---

## Bila terjadi masalah

### Satu salinan hilang atau rusak
Belum darurat — masih ada satu. Segera buat salinan pengganti dengan menjalankan
prosedur di atas, lalu catat kejadiannya.

### Kedua salinan hilang, tetapi server masih berjalan
**Belum terlambat, tetapi segera.** Kunci masih ada di berkas `.env` server.
Jalankan prosedur dari Langkah 1 hari itu juga.

### Kedua salinan hilang DAN server rusak
Data NIK dan nomor KK tidak dapat dipulihkan. Yang masih bisa diselamatkan:
nama, tanggal lahir, alamat, hubungan keluarga, dan seluruh arsip surat yang
sudah terbit — karena semua itu tidak dienkripsi.

NIK harus dikumpulkan ulang dari warga. Inilah keadaan yang dicegah oleh
dokumen ini.

### Kunci diduga bocor
Perlakukan sebagai insiden Sev-1 — ikuti `docs/08-keamanan-privasi.md` §9.
Kunci harus diganti dan seluruh data dienkripsi ulang; jangan lakukan sendiri
tanpa pendampingan admin teknis.

---

## Formulir (cetak dua lembar)

```
┌──────────────────────────────────────────────────────────────────────┐
│  KUNCI DATA PRIBADI — SISTEM INFORMASI DESA                          │
│  RAHASIA. Jangan difoto, jangan dikirim lewat pesan.                 │
├──────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  Desa      : ....................................................... │
│  Dibuat    : ......./......./20.......                               │
│  Salinan ke: ......  dari 2                                          │
│  Pemegang  : ....................................................... │
│  Disimpan  : ....................................................... │
│                                                                      │
├──────────────────────────────────────────────────────────────────────┤
│  PII_ENCRYPTION_KEY  (diawali "base64:")                             │
│                                                                      │
│  ................................................................... │
│                                                                      │
│  ................................................................... │
│                                                                      │
├──────────────────────────────────────────────────────────────────────┤
│  PII_HASH_PEPPER                                                     │
│                                                                      │
│  ................................................................... │
│                                                                      │
│  ................................................................... │
│                                                                      │
├──────────────────────────────────────────────────────────────────────┤
│  SIDIK JARI  (untuk pemeriksaan berkala — aman ditulis di luar)      │
│                                                                      │
│  Kunci enkripsi   : ........-........-........-........              │
│  Pepper pencarian : ........-........-........-........              │
│                                                                      │
├──────────────────────────────────────────────────────────────────────┤
│  Diverifikasi dengan "php artisan sid:pii-key --verify"              │
│                                                                      │
│  Hasil: [ ] COCOK      Tanggal: ......./......./20.......            │
│                                                                      │
│  Dibuat oleh : ..........................  ttd ..................... │
│  Disaksikan  : ..........................  ttd ..................... │
└──────────────────────────────────────────────────────────────────────┘
```
