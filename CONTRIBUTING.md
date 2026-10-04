# Panduan Pengembangan

## Menyiapkan lingkungan lokal

**Prasyarat:** PHP 8.3+, Composer 2, MySQL 8.0, Redis 7.

```bash
make setup      # pasang dependensi, siapkan .env, migrasi + data contoh
make serve      # http://localhost:8000
```

Akun contoh (hanya di lingkungan lokal) — kata sandi `kata-sandi-demo-2026`:

| Email | Peran | Berguna untuk menguji |
|-------|-------|------------------------|
| `admin@sukamaju.desa.id` | Admin Desa | Pemaksaan 2FA, akses penuh |
| `kades@sukamaju.desa.id` | Kepala Desa | Pemaksaan 2FA, hak persetujuan |
| `operator@sukamaju.desa.id` | Operator | Tanpa hak hapus & setujui (RULE-06) |
| `kadus.krajan@sukamaju.desa.id` | Kepala Dusun | **Pembatasan wilayah** |
| `kadus.sido@sukamaju.desa.id` | Kepala Dusun | Pembatasan wilayah (dusun lain) |
| `warga@sukamaju.desa.id` | Warga | **Pembatasan keluarga** (RULE-11) |
| `bpd@sukamaju.desa.id` | Pengawas | Read-only |

> ⚠ Untuk pemasangan nyata, **jangan** jalankan `DemoDataSeeder`. Buat akun
> administrator pertama dengan `php artisan sid:create-admin` — tidak ada kata
> sandi bawaan di dalam kode.

## Aturan yang tidak boleh dilanggar

### 1. Tabel sensitif hanya lewat lapis repository

Tabel `residents`, `families`, `letter_requests`, `letters`, dan `complaints`
memuat data pribadi warga. Karena MySQL tidak punya Row-Level Security (KEP-002),
**tidak ada jaring pengaman di basis data** bila satu kueri lupa menyaring
wilayah atau keluarga.

```php
// SALAH — akan menggagalkan tests/Architecture
$penduduk = Resident::query()->where('region_id', $id)->get();

// BENAR
$penduduk = app(ResidentRepository::class)->search($kataKunci, $filter);
```

Bila uji batas gagal: **jangan tambahkan pengecualian**, pindahkan kuerinya.

### 2. Data pribadi tidak pernah masuk log

NIK dan nomor KK adalah kelas K1 (docs/08 §1). `AuditLogger` menyensornya
otomatis, tetapi jangan mengandalkan itu saja — jangan pernah menulis
`Log::info($resident->revealNik())`.

Setiap pemanggilan `revealNik()` **wajib** disertai `AuditLogger::logPiiReveal()`
(RULE-07). Ini juga diperiksa `tests/Architecture`.

### 3. Otorisasi diperiksa di server, selalu

Menyembunyikan menu hanyalah kenyamanan. Kontrol sebenarnya adalah middleware
`izin:` dan lapis repository.

### 4. Migrasi harus mundur-kompatibel

Pola *expand → migrate → contract* (docs/10 §4.2). Menghapus kolom pada rilis
yang sama dengan kodenya membuat *rollback* tidak mungkin.

## Sebelum membuat pull request

```bash
make check     # pint + phpstan + seluruh pengujian — sama dengan CI
```

Definition of Done lengkap ada di `docs/09-testing-qa.md` §8.

## Catatan versi MySQL

Produksi menargetkan **MySQL 8.0**. Mesin pengembang boleh memakai versi lebih
baru, tetapi **CI dikunci ke 8.0** agar pemakaian fitur yang belum ada di 8.0
tertangkap sebelum rilis, bukan di server produksi.

## Perintah operasional

| Perintah | Kegunaan |
|----------|----------|
| `php artisan sid:create-admin` | Buat akun Admin Desa pertama (interaktif) |
| `php artisan sid:generate-pii-keys` | Hasilkan kunci enkripsi data pribadi |
| `php artisan sid:harden-audit-log` | Cabut UPDATE/DELETE pada audit log (**wajib di produksi**) |
| `php artisan sid:verify-audit-chain` | Periksa apakah audit log pernah diubah di luar aplikasi |
