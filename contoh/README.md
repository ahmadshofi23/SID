# Berkas Contoh

## `contoh-impor-penduduk.csv`

Berkas untuk mencoba fitur **Impor dari Excel** tanpa harus mengetik sendiri.

Isinya 16 baris yang sengaja dibuat campur: **11 baris sah** dan **5 baris
ditolak**, agar laporan pra-validasi benar-benar menunjukkan isinya.

| Baris | Kondisi | Yang terjadi |
|-------|---------|--------------|
| 2–11 | 10 penduduk wajar dalam 6 kartu keluarga | Masuk semua |
| 12 | NIK hanya 8 angka | **Ditolak** — "NIK harus 16 angka, ditemukan 8 angka" |
| 13 | NIK sama dengan baris 2 | **Ditolak** — "NIK ganda — sudah muncul di baris 2" |
| 14 | Nama kosong | **Ditolak** — "Nama lengkap wajib diisi" |
| 15 | Tanggal lahir 2090 | **Ditolak** — "Tanggal lahir melebihi hari ini" |
| 16 | Jenis kelamin "X" | **Ditolak** — "Jenis kelamin harus L atau P" |
| 17 | Kode RT 888 / Dusun Antah Berantah | **Tetap masuk**, tanpa wilayah |

Hasil yang sudah diverifikasi: **16 baris · 11 sah · 5 ditolak** → 11 penduduk
dan 6 kartu keluarga baru, lalu **1 anomali "penduduk tanpa wilayah"**.

Baris 17 sengaja demikian, dan **lolos pemeriksaan** — wilayah yang tidak
dikenali bukan alasan menolak orangnya. Yang tidak terjadi: wilayahnya **tidak
dibuat otomatis**, karena satu salah ketik kode RT akan melahirkan wilayah hantu
— dan wilayah menentukan hak akses Kepala Dusun. Orangnya tetap disimpan tanpa
wilayah, lalu muncul di halaman **Periksa Anomali**.

### Cara mencoba
1. Masuk sebagai `admin@sukamaju.desa.id` (lihat CONTRIBUTING.md untuk kode 2FA)
2. **Data Penduduk → Impor dari Excel**
3. Unggah berkas ini
4. Periksa laporan pra-validasi — **belum ada yang tersimpan**
5. Tekan **Jalankan impor** bila sudah yakin

> Seluruh NIK di berkas ini **sintetis**. Strukturnya valid 16 angka tetapi
> tidak merujuk orang nyata. Jangan pernah memakai data warga asli untuk uji
> coba (docs/09-testing-qa.md §3).
