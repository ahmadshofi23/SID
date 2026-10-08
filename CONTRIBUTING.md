# Panduan Pengembangan

## Menyiapkan lingkungan lokal

**Prasyarat:** PHP 8.3+, Composer 2, MySQL 8.0, Redis 7.

```bash
make setup      # pasang dependensi, siapkan .env, migrasi + data contoh
make serve      # http://localhost:8000 (PHP + Vite + queue worker)
```

> **Queue worker wajib jalan.** Impor massal dan render PDF diproses di latar
> belakang. Tanpa worker, halaman impor menggantung di "Menunggu" tanpa pesan
> galat — tidak ada yang tampak rusak, tetapi tidak ada yang terjadi.
> `make serve` sudah menjalankannya; di produksi, worker adalah proses terpisah
> (lihat `docker-compose.prod.yml` di docs/10 §3).

Akun contoh (hanya di lingkungan lokal) — kata sandi `kata-sandi-demo-2026`:

| Email | Peran | 2FA | Menu yang terlihat |
|-------|-------|-----|--------------------|
| `admin@sukamaju.desa.id` | Admin Desa | **wajib** | Semua, termasuk **Impor** & tombol buka NIK |
| `kades@sukamaju.desa.id` | Kepala Desa | **wajib** | Semua kecuali impor & kelola pengguna |
| `kasi.pem@sukamaju.desa.id` | Kasi Pemerintahan | — | Kependudukan, mutasi, statistik |
| `operator@sukamaju.desa.id` | Operator | — | Kependudukan (tanpa impor, hapus, buka NIK) |
| `kadus.krajan@sukamaju.desa.id` | Kepala Dusun | — | **Hanya Dusun Krajan** |
| `kadus.sido@sukamaju.desa.id` | Kepala Dusun | — | **Hanya Dusun Sidomulyo** |
| `bpd@sukamaju.desa.id` | Pengawas | — | Read-only + ekspor |
| `warga@sukamaju.desa.id` | Warga | — | **Hanya anggota KK-nya** |

### Masuk sebagai Admin Desa (2FA wajib)

Impor massal, pembukaan NIK penuh, dan pengelolaan pengguna **hanya dimiliki
Admin Desa** — dan peran itu wajib verifikasi dua langkah (FR-AUT-05). Tanpa
jalan keluar, seluruh fitur tersebut tidak dapat dicoba saat pengembangan.

```bash
php artisan sid:totp admin@sukamaju.desa.id
```

Perintah menampilkan kode 6 angka yang sedang berlaku. Ia **hanya bekerja di
lingkungan lokal** — di produksi, 2FA tidak boleh dapat dilewati dari shell.

Ingin memakai aplikasi autentikator sungguhan? Perintah yang sama juga
menampilkan rahasia yang dapat dimasukkan manual ke Google Authenticator/Aegis.

### Di mana fitur impor?

Dua tempat, keduanya hanya muncul bagi pemegang izin `penduduk.impor`:
- **Data Penduduk → tombol "Impor dari Excel"** (tempat orang mencarinya)
- **Menu samping → Kelola Data → Impor dari Excel**

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

## Kunci data pribadi

Kunci enkripsi NIK & nomor KK **tidak dapat dipulihkan bila hilang**. Prosedur
pencadangannya (dua pemegang, dicetak di kertas — KEP-006) ada di
[ops/runbook/pencadangan-kunci.md](ops/runbook/pencadangan-kunci.md) dan **wajib
dijalankan sebelum data warga asli yang pertama dimasukkan**.

| Perintah | Kegunaan |
|----------|----------|
| `php artisan sid:pii-key` | Tampilkan sidik jari kunci (aman dilihat orang lain) |
| `php artisan sid:pii-key --verify` | Periksa apakah salinan kertas cocok dengan server |

Tidak ada perintah yang menampilkan kunci itu sendiri — itu disengaja. Kunci yang
pernah tampil di layar tertinggal di riwayat terminal, rekaman rapat daring, dan
log sesi SSH.
