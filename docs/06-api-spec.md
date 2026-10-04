# 06 — Spesifikasi API
**Proyek:** Sistem Informasi Desa (SID) · **Versi:** 2.0 · **Gaya:** REST + OpenAPI 3.1
**Lingkup:** satu desa (KEP-001)

---

## 1. Konvensi

| Aspek | Ketentuan |
|-------|-----------|
| Basis URL | `https://<domain-desa>/api/v1` (mis. `https://sukamaju.desa.id/api/v1`) |
| Versi | Di dalam path (`/v1`). Perubahan yang merusak → `/v2`, `/v1` didukung ≥ 6 bulan |
| Format | JSON (`application/json; charset=utf-8`); unggah berkas `multipart/form-data` |
| Penamaan | Path & *query* `snake_case`, jamak (`/residents`); *field* JSON `snake_case` |
| Waktu | ISO 8601 UTC (`2026-10-04T07:15:00Z`); klien yang menampilkan melakukan konversi zona |
| Uang | Integer rupiah (tanpa desimal), bukan *float* |
| ID | UUID v7 |
| Autentikasi | `Authorization: Bearer <token>` (panel internal memakai sesi *cookie* + CSRF) |
| Lingkup data | Satu desa per instalasi; **tidak ada** parameter desa/tenant di API |
| Bahasa pesan | Indonesia (`Accept-Language: id` default) |
| Idempotensi | Header `Idempotency-Key: <uuid>` pada POST yang membuat sumber daya |
| Correlation | Server mengembalikan `X-Correlation-Id`; wajib disertakan saat melaporkan galat |

### Penamaan aksi non-CRUD
Transisi status memakai **sub-resource aksi**, bukan `PATCH status` bebas —
agar otorisasi dan validasi per transisi jelas:
```
POST /letter-requests/{id}/submit
POST /letter-requests/{id}/verify
POST /letter-requests/{id}/approve
POST /letter-requests/{id}/reject
POST /letter-requests/{id}/request-revision
```

---

## 2. Format Respons

### 2.1 Satu objek
```json
{
  "data": {
    "id": "018f2a1c-7b3e-7000-8000-000000000001",
    "full_name": "Siti Aminah",
    "nik": "3201••••••••1234",
    "gender": "P",
    "birth_date": "1985-03-17",
    "status": "aktif",
    "region": { "id": "...", "name": "RT 003", "path": "Dusun Krajan / RW 02 / RT 003" },
    "created_at": "2026-01-10T03:20:11Z"
  },
  "meta": { "correlation_id": "018f2a1c-..." }
}
```

### 2.2 Koleksi (paginasi kursor untuk daftar besar)
```json
{
  "data": [ { "...": "..." } ],
  "meta": {
    "per_page": 50,
    "total": 5231,
    "has_more": true,
    "next_cursor": "eyJpZCI6IjAxOGYyYTFjLi4uIn0"
  },
  "links": { "next": "/api/v1/residents?cursor=eyJpZCI6...&per_page=50" }
}
```
> Paginasi **kursor** untuk daftar besar (penduduk, audit log) agar stabil & cepat;
> paginasi **nomor halaman** hanya untuk daftar yang kecil dan perlu "halaman ke-N".

### 2.3 Galat (seragam untuk semua *endpoint*)
```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Data yang dikirim tidak valid.",
    "details": [
      { "field": "nik", "code": "NIK_DUPLICATE",
        "message": "NIK ini sudah terdaftar pada penduduk lain yang masih aktif." },
      { "field": "birth_date", "code": "DATE_IN_FUTURE",
        "message": "Tanggal lahir tidak boleh melebihi hari ini." }
    ],
    "correlation_id": "018f2a1c-7b3e-7000-8000-00000000abcd"
  }
}
```

**Kode galat & HTTP status**

| HTTP | `code` | Makna |
|------|--------|-------|
| 400 | `BAD_REQUEST` | Format permintaan salah |
| 401 | `UNAUTHENTICATED` | Token tidak ada/kedaluwarsa |
| 401 | `TWO_FACTOR_REQUIRED` | Perlu langkah 2FA |
| 403 | `FORBIDDEN` | Terotentikasi tetapi tidak berwenang |
| 404 | `NOT_FOUND` | Tidak ada **atau** di luar lingkup pengguna — wilayah lain / keluarga lain (disengaja, SRS §4.9) |
| 409 | `INVALID_STATE_TRANSITION` | Transisi status tidak sah |
| 409 | `CONFLICT` | Bentrok data (mis. NIK sudah ada) |
| 409 | `IDEMPOTENCY_CONFLICT` | `Idempotency-Key` sama dengan isi berbeda |
| 413 | `PAYLOAD_TOO_LARGE` | Berkas melebihi batas |
| 415 | `UNSUPPORTED_MEDIA_TYPE` | Tipe berkas tidak diizinkan |
| 422 | `VALIDATION_FAILED` | Validasi gagal (lihat `details`) |
| 422 | `BUSINESS_RULE_VIOLATION` | Aturan bisnis dilanggar (mis. RULE-02, RULE-06) |
| 429 | `RATE_LIMITED` | Terlalu banyak permintaan (lihat `Retry-After`) |
| 500 | `INTERNAL_ERROR` | Galat server (detail hanya di log) |
| 503 | `SERVICE_UNAVAILABLE` | Mode pemeliharaan / dependensi tidak siap |

**Prinsip:** pesan `message` ditujukan untuk **ditampilkan kepada pengguna** dalam
bahasa Indonesia; `code` untuk logika klien. Jangan pernah mengembalikan *stack trace*.

---

## 3. Autentikasi

| Method | Endpoint | Keterangan |
|--------|----------|-----------|
| POST | `/auth/login` | `{identifier, password}` → token + `requires_2fa` |
| POST | `/auth/two-factor` | `{challenge_token, code}` → token akses |
| POST | `/auth/refresh` | Perpanjang token |
| POST | `/auth/logout` | Cabut token aktif |
| POST | `/auth/logout-all` | Cabut semua sesi pengguna |
| POST | `/auth/forgot-password` | Kirim tautan/OTP. **Selalu** 200 (anti-enumerasi, FR-AUT-10) |
| POST | `/auth/reset-password` | `{token, password, password_confirmation}` |
| POST | `/auth/register` | Registrasi warga (NIK + no. KK + nama + kontak) |
| GET | `/auth/me` | Profil, peran, daftar izin efektif, batasan wilayah |

**Contoh — login dengan 2FA**
```http
POST /api/v1/auth/login
{ "identifier": "sekdes@desasukamaju.id", "password": "••••••••••" }

200 OK
{ "data": { "requires_2fa": true,
            "challenge_token": "chg_018f...",
            "expires_in": 300 } }
```
```http
POST /api/v1/auth/two-factor
{ "challenge_token": "chg_018f...", "code": "482913" }

200 OK
{ "data": { "access_token": "...", "token_type": "Bearer", "expires_in": 1800,
            "user": { "id": "...", "name": "Budi Santoso",
                      "roles": ["admin_desa"],
                      "permissions": ["penduduk.lihat", "penduduk.ubah", "surat.setujui"],
                      "region_scope": null } } }
```

**Rate limit:** `/auth/login` 5 permintaan / 15 menit / (IP + identifier);
`/auth/forgot-password` 3 / jam / identifier.

---

## 4. Endpoint Kependudukan

| Method | Endpoint | Izin | Keterangan |
|--------|----------|------|-----------|
| GET | `/residents` | `penduduk.lihat` | Daftar + filter + pencarian |
| POST | `/residents` | `penduduk.tambah` | Tambah penduduk |
| GET | `/residents/{id}` | `penduduk.lihat` | Detail (NIK ter-masking sesuai peran) |
| PATCH | `/residents/{id}` | `penduduk.ubah` | Perbarui sebagian |
| DELETE | `/residents/{id}` | `penduduk.hapus` | *Soft delete*, `{reason}` wajib |
| POST | `/residents/{id}/restore` | `penduduk.hapus` | Pulihkan |
| GET | `/residents/{id}/history` | `penduduk.lihat` | Riwayat perubahan (FR-PDK-07) |
| GET | `/residents/{id}/letters` | `surat.lihat` | Riwayat layanan penduduk |
| POST | `/residents/import` | `penduduk.impor` | Unggah berkas → `job_id` |
| GET | `/imports/{job_id}` | `penduduk.impor` | Progres & laporan galat |
| POST | `/residents/export` | `penduduk.ekspor` | **Tercatat di audit log** → `job_id` |
| GET | `/residents/anomalies` | `penduduk.lihat` | Laporan anomali (FR-PDK-13) |
| GET | `/families` · `POST` · `GET/{id}` · `PATCH/{id}` | `keluarga.*` | CRUD KK |
| POST | `/families/{id}/members` | `keluarga.ubah` | Tambah/pindahkan anggota |
| GET | `/mutations` · `POST` | `mutasi.*` | Mutasi penduduk |
| GET | `/regions` · `POST` · `PATCH/{id}` | `wilayah.*` | Hierarki wilayah |
| GET | `/statistics/population` | publik (agregat) | Statistik; sel < 5 disamarkan |

**Parameter daftar penduduk**
```
GET /api/v1/residents
    ?q=siti                     # nama (toleran salah ketik) / NIK persis / no. KK
    &region_id=<uuid>           # termasuk sub-wilayah
    &gender=P
    &age_min=17&age_max=60
    &status=aktif
    &religion_code=islam
    &sort=full_name             # -full_name untuk menurun
    &per_page=50&cursor=...
```

**Contoh — tambah penduduk**
```http
POST /api/v1/residents
Idempotency-Key: 018f2a1c-7b3e-7000-8000-00000000f00d
{
  "nik": "3201234567890123",
  "full_name": "Siti Aminah",
  "birth_place": "Sukamaju",
  "birth_date": "1985-03-17",
  "gender": "P",
  "family_id": "018f2a1c-...",
  "family_relation_code": "istri",
  "religion_code": "islam",
  "marital_status_code": "kawin_tercatat",
  "education_code": "sltp",
  "occupation_code": "petani",
  "region_id": "018f2a1c-...",
  "address": "Jl. Melati No. 12"
}

201 Created
Location: /api/v1/residents/018f2a1c-7b3e-7000-8000-000000000055
```
**422 — contoh pelanggaran aturan bisnis**
```json
{ "error": {
  "code": "BUSINESS_RULE_VIOLATION",
  "message": "Kartu keluarga ini sudah memiliki kepala keluarga.",
  "details": [ { "field": "family_relation_code", "code": "DUPLICATE_FAMILY_HEAD",
                 "message": "Kepala keluarga saat ini: Ahmad Hidayat. Ubah data yang lama terlebih dahulu." } ],
  "correlation_id": "018f..." } }
```

---

## 5. Endpoint Persuratan

> Katalog jenis surat bawaan (22 jenis, 8 aktif) ada di [dokumen 13](13-katalog-surat.md).

| Method | Endpoint | Izin | Keterangan |
|--------|----------|------|-----------|
| GET | `/letter-types` | publik (yang `is_public` & `is_active`) | Daftar layanan + syarat + SLA |
| POST | `/letter-types` | `jenis_surat.kelola` | Tambah jenis surat |
| PATCH | `/letter-types/{id}` | `jenis_surat.kelola` | Ubah template/skema |
| POST | `/letter-types/{id}/preview` | `jenis_surat.kelola` | Pratinjau PDF dengan data contoh |
| GET | `/letter-requests` | `surat.lihat` | Daftar pengajuan (tersaring per peran) |
| POST | `/letter-requests` | `surat.ajukan` | Buat pengajuan (warga/*walk-in*) |
| GET | `/letter-requests/{id}` | `surat.lihat` | Detail + riwayat status |
| PATCH | `/letter-requests/{id}` | `surat.ubah` | Hanya saat `draft`/`perlu_revisi` |
| POST | `/letter-requests/{id}/submit` | `surat.ajukan` | → `diajukan` |
| POST | `/letter-requests/{id}/verify` | `surat.verifikasi` | → `diverifikasi` |
| POST | `/letter-requests/{id}/request-revision` | `surat.verifikasi` | → `perlu_revisi`, `{note}` wajib |
| POST | `/letter-requests/{id}/reject` | `surat.verifikasi` | → `ditolak`, `{reason}` wajib |
| POST | `/letter-requests/{id}/approve` | `surat.setujui` | → `diterbitkan`, **nomor terbit di sini** |
| POST | `/letter-requests/{id}/attachments` | `surat.ajukan` | Unggah syarat |
| GET | `/letters` | `surat.lihat` | Arsip surat terbit |
| GET | `/letters/{id}/pdf` | `surat.lihat` | *Pre-signed URL* (berlaku 5 menit) |
| POST | `/letters/{id}/cancel` | `surat.batalkan` | `{reason}` wajib |
| GET | `/public/verify/{code}` | **publik** | Verifikasi QR (data minimal) |

**Contoh — persetujuan & penerbitan**
```http
POST /api/v1/letter-requests/018f.../approve
{ "signer_user_id": "018f...", "note": "Disetujui" }

200 OK
{ "data": {
    "letter_id": "018f2a1c-...",
    "letter_number": "145/SKTM/32.07.12.2001/X/2026",
    "issued_at": "2026-10-04T08:12:45Z",
    "verification_code": "8KD3M2QX",
    "pdf_url": "https://.../letters/018f....pdf?X-Amz-Expires=300",
    "pdf_hash": "a3f1..." } }
```
**409 — transisi tidak sah**
```json
{ "error": {
  "code": "INVALID_STATE_TRANSITION",
  "message": "Pengajuan harus diverifikasi terlebih dahulu sebelum disetujui.",
  "details": [ { "field": "status", "code": "EXPECTED_STATUS",
                 "message": "Status saat ini: diajukan. Status yang diharapkan: diverifikasi." } ] } }
```
**422 — pemisahan kewenangan (RULE-06)**
```json
{ "error": { "code": "BUSINESS_RULE_VIOLATION",
  "message": "Anda tidak dapat menyetujui pengajuan yang Anda buat sendiri.",
  "details": [ { "field": "approver", "code": "SELF_APPROVAL_FORBIDDEN" } ] } }
```

**Verifikasi publik — respons sengaja minimal (RULE-07):**
```http
GET /api/v1/public/verify/8KD3M2QX

200 OK
{ "data": {
    "valid": true,
    "letter_number": "145/SKTM/32.07.12.2001/X/2026",
    "letter_type": "Surat Keterangan Tidak Mampu",
    "applicant_name": "Siti A••••••",
    "issued_at": "2026-10-04",
    "issued_by": "Desa Sukamaju, Kec. Sukaraja, Kab. Contoh",
    "status": "sah" } }
```
Tidak ada NIK, alamat, isi surat, atau tautan PDF di respons ini.
*Rate limit* 20/menit/IP untuk mencegah penebakan kode.

---

## 6. Endpoint Lain

### Pengaduan
| Method | Endpoint | Izin |
|--------|----------|------|
| POST | `/complaints` | publik (dengan CAPTCHA + *rate limit*) |
| GET | `/complaints` | `pengaduan.lihat` |
| GET | `/complaints/{id}` | `pengaduan.lihat` |
| GET | `/public/complaints/track/{tracking_code}` | publik |
| POST | `/complaints/{id}/assign` | `pengaduan.tugaskan` |
| POST | `/complaints/{id}/actions` | `pengaduan.tindaklanjut` |
| POST | `/complaints/{id}/resolve` | `pengaduan.tindaklanjut` |
| POST | `/complaints/{id}/reject` | `pengaduan.tindaklanjut` |

### Portal & Konten
`GET/POST/PATCH/DELETE /posts` · `GET /public/posts` · `GET /public/posts/{slug}` ·
`GET /public/profile` · `GET /public/statistics` · `GET /public/services` ·
`GET /public/documents`

### Administrasi
`/users` · `/users/{id}/roles` · `/users/{id}/deactivate` · `/users/{id}/verify` ·
`/roles` · `/permissions` · `/officials` · `/village-profile` · `/settings` ·
`/audit-logs` · `/references/{type}` · `/dashboard/summary` · `/reports/{slug}` ·
`/jobs/{id}` (status pekerjaan asinkron)

---

## 7. Pekerjaan Asinkron

Operasi berat (impor, ekspor > 5.000 baris, render PDF massal, laporan besar)
mengembalikan **202 Accepted**:
```json
{ "data": { "job_id": "018f...", "status": "menunggu",
            "poll_url": "/api/v1/jobs/018f..." } }
```
Status *polling*:
```json
{ "data": { "job_id": "018f...", "status": "diproses",
            "progress": 62, "processed": 12400, "total": 20000,
            "result_url": null, "errors_url": null } }
```
Status akhir: `selesai` (dengan `result_url` berlaku 24 jam) atau `gagal`
(dengan `error.message` yang dapat dipahami pengguna).
Interval *polling* yang disarankan: 2 detik, naik bertahap ke 10 detik.

---

## 8. Unggah Berkas

```http
POST /api/v1/letter-requests/{id}/attachments
Content-Type: multipart/form-data

file: <binary>
label: "Fotokopi Kartu Keluarga"
```
**Ketentuan:**
- Tipe diizinkan: `image/jpeg`, `image/png`, `application/pdf` — diverifikasi lewat
  *magic bytes*, **bukan** ekstensi atau `Content-Type` kiriman klien.
- Maks 5 MB/berkas, 10 berkas/pengajuan.
- Nama berkas diganti UUID; nama asli hanya disimpan sebagai metadata.
- Gambar dibersihkan metadata EXIF (termasuk koordinat GPS) — perlindungan privasi.
- Akses hanya lewat *pre-signed URL* 5 menit; tidak ada URL publik permanen.
- Berkas dipindai malware; status `menunggu` → belum dapat diunduh.

---

## 9. Keamanan API

| Kontrol | Penerapan |
|---------|-----------|
| CORS | *Allowlist* domain desa saja; tidak ada `*` |
| CSRF | Wajib untuk sesi *cookie*; token Bearer dikecualikan |
| *Rate limit* | Global 120/menit/pengguna · login 5/15 menit · pengaduan publik 3/jam/IP · verifikasi QR 20/menit/IP |
| *Mass assignment* | *Whitelist field* per aksi; `status`, `id`, `role_id`, `resident_id` **tidak pernah** diterima dari klien |
| IDOR | Setiap akses objek diperiksa terhadap izin + kepemilikan (wilayah/keluarga) di lapis *repository* (04 §4.1) |
| Pembocoran lewat galat | Objek di luar lingkup pengguna → 404, bukan 403 |
| *Masking* | NIK/no. KK dikembalikan ter-masking kecuali izin `data_pribadi.lihat_penuh`; **setiap** pengungkapan penuh tercatat di audit log |
| Ukuran permintaan | Maks 1 MB untuk JSON, 5 MB untuk berkas |
| Kedalaman JSON | Maks 10 tingkat (proteksi *parser*) |
| Header keamanan | CSP, HSTS, X-Content-Type-Options, Referrer-Policy pada semua respons |

---

## 10. Dokumentasi & Kontrak

- Spesifikasi **OpenAPI 3.1** di `/docs/openapi.yaml`, dihasilkan dari anotasi kode
  agar tidak pernah basi (NFR-MNT-04).
- UI interaktif di `/docs/api` (hanya untuk lingkungan non-produksi).
- *Collection* Postman/Bruno disertakan di `ops/api-collection/`.
- **Uji kontrak** di CI: respons nyata divalidasi terhadap skema OpenAPI; ketidaksesuaian
  menggagalkan *build*.
- *Changelog* API disimpan di `docs/api-changelog.md`; setiap perubahan yang merusak
  wajib diumumkan ≥ 30 hari sebelum berlaku.
