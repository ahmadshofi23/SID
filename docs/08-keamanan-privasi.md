# 08 — Keamanan & Pelindungan Data Pribadi
**Proyek:** Sistem Informasi Desa (SID) · **Versi:** 2.0 · **Lingkup:** satu desa · **DB:** MySQL 8

> Dokumen ini adalah bagian **terpenting** dari paket perancangan. Sistem ini menyimpan
> NIK, nomor KK, data keluarga, dan status ekonomi ribuan warga. Kebocoran data di sini
> berakibat pada penyalahgunaan identitas, penipuan pinjaman daring, dan sanksi pidana
> bagi pengendali data menurut **UU No. 27/2022 (PDP)**.
>
> Catatan: dokumen ini adalah panduan teknis dan **bukan nasihat hukum**. Verifikasi
> kewajiban hukum dengan penasihat hukum/DPMD.

---

## 1. Klasifikasi Data

| Kelas | Contoh | Perlakuan |
|-------|--------|-----------|
| **K1 — Sangat Rahasia** | NIK, nomor KK, NIK orang tua, foto KTP, data disabilitas, status kemiskinan | Enkripsi kolom; akses berbasis izin khusus; **setiap** pengungkapan penuh tercatat; tidak boleh keluar lingkungan produksi; **tidak pernah** di log |
| **K2 — Rahasia** | Nama lengkap + alamat + tanggal lahir, nomor telepon, isi pengaduan, catatan internal | Terenkripsi saat transit & diam; akses berbasis peran; ter-masking pada daftar |
| **K3 — Internal** | Statistik per RT, data layanan, data aset, audit log | Hanya pengguna terotentikasi |
| **K4 — Publik** | Profil desa, berita, statistik agregat, APBDes terpublikasi, nomor surat | Bebas diakses |

**Aturan mutlak:**
1. Data K1/K2 **dilarang** ada di lingkungan *staging*, *development*, laptop
   pengembang, grup WhatsApp, tiket *issue tracker*, atau lampiran email.
2. Data K1 **tidak pernah** muncul di log aplikasi, log web server, *query string*,
   pesan galat, atau laporan *crash*.
3. Ekspor data K1/K2 **selalu** tercatat lengkap: siapa, kapan, filter apa, berapa baris.

---

## 2. Dasar Hukum Pemrosesan (UU PDP)

| Aspek | Penerapan |
|-------|-----------|
| **Dasar pemrosesan** | Pelaksanaan kewajiban hukum pemerintah desa + pelaksanaan tugas kepentingan publik. **Bukan** sekadar persetujuan — karena desa wajib menyelenggarakan administrasi kependudukan |
| **Persetujuan** | Tetap diminta untuk pemrosesan di luar kewajiban: publikasi foto kegiatan, pengiriman notifikasi WhatsApp, publikasi nama penerima bantuan |
| **Pengendali data** | **Pemerintah Desa** (bukan vendor) |
| **Prosesor data** | Penyedia pengembangan & hosting → **wajib** ada Perjanjian Pemrosesan Data (DPA) |
| **Pembatasan tujuan** | Data layanan tidak boleh dipakai untuk tujuan lain (mis. kampanye politik, pemasaran) |
| **Minimalisasi** | Hanya kumpulkan *field* yang benar-benar dipakai. Setiap *field* baru harus menyebutkan alasan di CR |
| **Akurasi** | Mekanisme koreksi data (FR-PDK-16) |
| **Retensi** | Lihat dokumen 05 §7 |
| **Lokasi penyimpanan** | **Wajib** pusat data di Indonesia (NFR-CMP-02) |

### Hak subjek data yang harus didukung
| Hak | Implementasi |
|-----|--------------|
| Mengetahui | Halaman **Kebijakan Privasi** di portal (bahasa sederhana), wajib ada sebelum go-live |
| Mengakses | Warga melihat data dirinya di portal warga; ada prosedur permintaan tertulis bagi yang tidak berakun |
| Memperbaiki | Pengajuan koreksi data lewat portal/RT; SLA tanggapan 7 hari kerja |
| Menghapus | Terbatas — data kependudukan adalah kewajiban hukum. Data opsional (telepon, email, foto) dapat dihapus |
| Menarik persetujuan | Berhenti dari notifikasi WhatsApp/email; penarikan publikasi foto |
| Mengajukan keberatan | Kanal pengaduan + penanggung jawab pelindungan data desa yang ditunjuk via SK |

---

## 3. Keamanan Autentikasi & Sesi

| Kontrol | Ketentuan |
|---------|-----------|
| Penyimpanan kata sandi | Argon2id (`m=64MB, t=3, p=4`) atau bcrypt cost ≥ 12 |
| Kebijakan kata sandi | Minimal **10 karakter**; dicek terhadap daftar kata sandi bocor (k-anonymity HIBP atau daftar lokal); **tanpa** pemaksaan ganti berkala (mengikuti NIST SP 800-63B) |
| 2FA | **Wajib** untuk SA, AD, KD (FR-AUT-05). TOTP (lebih disukai) atau OTP SMS/WA. Kode pemulihan sekali pakai disediakan |
| Pembatasan percobaan | 5 gagal → jeda bertingkat (1s, 2s, 4s, …); 10 gagal → kunci 30 menit + notifikasi email |
| Token sesi | Acak kriptografis ≥ 256 bit; *cookie* `HttpOnly; Secure; SameSite=Lax`; diputar (*rotate*) saat login & saat naik hak |
| Masa sesi | Panel admin: 30 menit tidak aktif, maksimum absolut 12 jam. Warga: 14 hari (opsi "ingat saya") |
| Keluar menyeluruh | Pencabutan semua sesi berlaku **seketika** (daftar cabut di Redis, bukan hanya hapus *cookie*) |
| Anti-enumerasi | Pesan login/reset/registrasi selalu identik untuk akun ada & tidak ada (FR-AUT-10) |
| Akun bawaan | **Tidak ada** kata sandi bawaan. Admin pertama dibuat lewat perintah terminal saat pemasangan, wajib ganti sandi pada login pertama |

---

## 4. Otorisasi (Risiko Tertinggi Sistem Ini)

Kerentanan paling umum pada aplikasi semacam ini adalah **IDOR** — bukan SQL injection.

Dengan lingkup satu desa (KEP-001), risiko kebocoran antar-desa tidak ada. Namun
dengan MySQL (KEP-002) **tidak ada Row-Level Security**, sehingga dua pembatasan
berikut sepenuhnya bergantung pada kode:

**Lapis 1 — Lapis *repository* wajib.** Semua kueri ke `residents`, `families`,
`letter_requests`, dan `complaints` **harus** lewat satu kelas *repository* yang
menerapkan pembatasan wilayah (Kepala Dusun) dan keluarga (Warga). Kontroler
**dilarang** membangun kueri sendiri ke empat tabel itu; aturan ini ditegakkan oleh
analisis statis di CI (04 §4.1). Tanpa lapis ini, satu kueri yang lupa menyaring
akan membocorkan NIK seluruh warga desa.

**Lapis 2 — Otorisasi objek.** Setiap pengambilan objek berjalan melalui *policy*:
```
dapatPenduduk(id, pengguna):
    penduduk = repository.cari(id, pengguna)  # pembatasan wilayah/keluarga di sini
    jika tidak ada                 → 404
    jika !pengguna.izin('penduduk.lihat')     → 403
    jika pengguna.peran == 'kepala_dusun'
        dan penduduk.region ∉ pengguna.wilayah → 404   # bukan 403
    jika pengguna.peran == 'warga'
        dan penduduk.family_id ≠ pengguna.family_id → 404
    kembalikan topeng(penduduk, pengguna)
```
Mengembalikan **404** (bukan 403) untuk objek di luar lingkup mencegah penyerang
memastikan keberadaan suatu data.

**Lapis 3 — Pemisahan kewenangan.** Pembuat ≠ penyetuju (RULE-06); operator tidak
dapat menghapus; pengguna tidak dapat menaikkan hak aksesnya sendiri; perubahan
peran selalu tercatat dan memberi notifikasi ke Admin Desa.

**Lapis 4 — Pengujian.** *Test suite* otorisasi khusus (dokumen 09 §4.3) yang
menjalankan **setiap kombinasi (peran × endpoint × kepemilikan objek)**.

> Dengan MySQL, lapis ini adalah **satu-satunya** verifikasi otomatis bahwa pembatasan
> wilayah & keluarga benar-benar bekerja. Pada PostgreSQL ada RLS sebagai jaring
> pengaman; di sini tidak. Karena itu ia wajib, dan kegagalannya memblokir rilis.

---

## 5. Perlindungan Data

| Lapis | Kontrol |
|-------|---------|
| **Transit** | TLS 1.2+ (prefer 1.3), HSTS `max-age=31536000; includeSubDomains`, HTTP → HTTPS, *cipher suite* modern saja |
| **Saat diam** | Enkripsi disk/volume + **enkripsi tingkat kolom** untuk NIK & nomor KK (AES-256-GCM di lapis aplikasi, ADR-004). **Jangan** memakai `AES_ENCRYPT()` MySQL — kuncinya akan bocor ke *slow query log*, *binlog*, dan `SHOW PROCESSLIST` |
| **Kunci** | Disimpan di *secret manager* / variabel lingkungan dengan izin berkas ketat; **tidak pernah** di repositori; rotasi tahunan dengan prosedur *re-encrypt* terdokumentasi |
| **Cadangan** | Terenkripsi sebelum dikirim ke penyimpanan; kunci cadangan disimpan terpisah dari data |
| **Masking** | NIK → `3201••••••••1234`; telepon → `08••••••789`. Default ter-masking; pengungkapan penuh butuh izin `data_pribadi.lihat_penuh` **dan** tercatat |
| **Berkas** | S3 privat; akses hanya lewat *pre-signed URL* 5 menit; **tidak ada** direktori unggahan yang dapat dijelajahi; EXIF (termasuk GPS) dihapus dari gambar |
| **Log** | Penyaring otomatis menyensor pola NIK (16 digit), nomor telepon, email, token, dan kata sandi sebelum ditulis ke log. Berlaku juga untuk **MySQL *slow query log*** — matikan `log_queries_not_using_indexes` di produksi dan batasi akses berkasnya |
| **Staging** | Data produksi **wajib** di-anonimkan: NIK diganti sintetis valid, nama diganti dari kamus nama, alamat digeneralisasi, telepon & email diganti |

---

## 6. Keamanan Aplikasi (OWASP Top 10)

| Risiko | Mitigasi | Verifikasi |
|--------|----------|-----------|
| **A01 Broken Access Control** | §4 di atas | Uji otorisasi otomatis (wajib lulus di CI) |
| **A02 Cryptographic Failures** | §5 | Tinjauan konfigurasi TLS; uji SSL Labs ≥ A |
| **A03 Injection** | *Query* ber-parameter / ORM saja; **dilarang** merangkai SQL dari masukan; template *auto-escape* | Analisis statis + uji SQLi |
| **A04 Insecure Design** | Pemodelan ancaman per modul sebelum implementasi; aturan bisnis ditegakkan di server | Tinjauan desain |
| **A05 Security Misconfiguration** | Pengerasan konfigurasi; *debug mode* mati di produksi; halaman galat tanpa detail; direktori tidak dapat dijelajahi | *Checklist* pra-rilis |
| **A06 Vulnerable Components** | Pemindaian dependensi otomatis; pemutakhiran bulanan; *Critical/High* menggagalkan *build* | CI |
| **A07 Auth Failures** | §3 | Uji penetrasi |
| **A08 Integrity Failures** | *Lockfile* dependensi; *checksum* unggahan; audit log ber-*hash-chain*; artefak rilis ditandatangani | Tinjauan CI |
| **A09 Logging Failures** | Audit log lengkap (FR-ADM-04); peringatan atas pola mencurigakan | Uji peninjauan log |
| **A10 SSRF** | Tidak ada pengambilan URL dari masukan pengguna; bila perlu → *allowlist* domain + blokir rentang IP internal | Tinjauan kode |

### Unggahan berkas (jalur serangan paling umum)
1. Validasi tipe nyata lewat *magic bytes* — **bukan** ekstensi atau `Content-Type`.
2. Nama berkas diganti UUID; ekstensi dari tipe terdeteksi.
3. Disimpan di S3 **di luar** *web root*; tidak pernah dieksekusi.
4. Gambar diproses ulang (*re-encode*) untuk menghilangkan muatan tersembunyi.
5. Dipindai antivirus (ClamAV); status `menunggu` sampai bersih.
6. PDF disajikan dengan `Content-Disposition: attachment` dan
   `X-Content-Type-Options: nosniff`.

### Header keamanan (semua respons)
```
Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:;
    style-src 'self' 'unsafe-inline'; script-src 'self'; object-src 'none';
    frame-ancestors 'none'; base-uri 'self'; form-action 'self'
Strict-Transport-Security: max-age=31536000; includeSubDomains
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: geolocation=(self), camera=(self), microphone=()
```
> CSP wajib diterapkan **sejak sprint 1**. Menambahkannya setelah aplikasi selesai
> jauh lebih sulit karena banyak skrip *inline* yang sudah tersebar.

---

## 7. Siklus Pengembangan Aman (SSDLC)

| Tahap | Aktivitas keamanan |
|-------|--------------------|
| Perancangan | Pemodelan ancaman (STRIDE) per modul; tinjauan klasifikasi data untuk setiap *field* baru |
| Pengodean | Panduan koding aman; *pre-commit hook* mendeteksi *secret*; dilarang `console.log` data pribadi |
| *Pull request* | Wajib ditinjau ≥ 1 orang; *checklist* keamanan untuk PR yang menyentuh autentikasi/otorisasi/data pribadi |
| CI | SAST, pemindaian dependensi, deteksi *secret*, uji otorisasi, pemindaian konfigurasi |
| Pra-rilis | DAST (OWASP ZAP), *checklist* pengerasan, tinjauan izin basis data |
| Pra-go-live | **Uji penetrasi** oleh pihak ketiga; temuan Critical/High **wajib** ditutup |
| Operasi | Pemantauan, pemutakhiran keamanan bulanan, peninjauan audit log kuartalan, uji pemulihan cadangan kuartalan |
| Tahunan | Tinjauan hak akses (hapus akun perangkat desa yang sudah tidak menjabat), tinjauan kebijakan privasi |

---

## 8. Pemantauan & Deteksi

**Peristiwa yang memicu peringatan:**
| Peristiwa | Ambang | Tindakan |
|-----------|--------|----------|
| Login gagal | > 20 dari satu IP / 10 menit | Blokir IP sementara + peringatan |
| Ekspor data besar | > 1.000 baris | Peringatan seketika ke Admin Desa |
| Pengungkapan NIK penuh | > 50 dalam 1 jam oleh satu pengguna | Peringatan + tinjauan |
| Akses objek di luar wilayah/keluarga pengguna | ≥ 1 | Peringatan prioritas tinggi (menandakan bug otorisasi atau serangan) |
| Perubahan peran/izin | ≥ 1 | Notifikasi ke Admin Desa & log |
| Login di luar jam kerja oleh peran admin | ≥ 1 | Notifikasi informatif |
| Rasio galat 5xx | > 1% / 5 menit | Peringatan operasional |
| Pekerjaan *queue* gagal | > 10 | Peringatan operasional |
| Cadangan gagal | ≥ 1 | Peringatan prioritas tinggi |

**Yang dicatat untuk setiap permintaan:** waktu, `correlation_id`, pengguna, peran,
metode, path (tanpa data sensitif), status, durasi, IP, *user agent*.
**Retensi log:** aplikasi 90 hari; audit log 5 tahun.

---

## 9. Tanggap Insiden

**Klasifikasi**
| Tingkat | Definisi | Target respons |
|---------|----------|----------------|
| **Sev-1** | Dugaan kebocoran data pribadi; sistem tidak dapat diakses | Mulai ≤ 1 jam |
| **Sev-2** | Kerentanan dieksploitasi tanpa bukti kebocoran; modul kritis rusak | ≤ 4 jam |
| **Sev-3** | Kerentanan ditemukan, belum dieksploitasi | ≤ 2 hari kerja |
| **Sev-4** | Gangguan minor | Sprint berikutnya |

**Prosedur kebocoran data pribadi (Sev-1)**
1. **Bendung (0–1 jam):** isolasi sistem/akun terdampak, cabut kredensial & token,
   aktifkan mode pemeliharaan bila perlu. **Jangan hapus log** — itu bukti.
2. **Nilai (1–8 jam):** tentukan data apa, berapa subjek data, rentang waktu, jalur
   masuk. Dokumentasikan garis waktu.
3. **Beri tahu (≤ 3 × 24 jam):** **kewajiban UU PDP** — beri tahu subjek data
   terdampak **dan** lembaga pengawas pelindungan data, serta Kepala Desa/DPMD.
   Siapkan templat pemberitahuan **sebelum** insiden terjadi.
4. **Pulihkan:** tutup celah, pulihkan dari cadangan bersih, paksa reset kata sandi.
5. **Evaluasi (≤ 14 hari):** *post-mortem* tanpa menyalahkan individu; perbaikan
   masuk *backlog* dengan prioritas tertinggi.

**Kontak (wajib diisi sebelum go-live):**
| Peran | Nama | Kontak |
|-------|------|--------|
| Penanggung jawab pelindungan data desa | | |
| Kontak teknis utama | | |
| Penanggung jawab penyedia/vendor | | |
| Kepala Desa | | |

---

## 10. Checklist Pra-Go-Live (Keamanan)

**Wajib seluruhnya tercentang. Tanpa ini, sistem tidak boleh menerima data warga asli.**

- [ ] HTTPS aktif dengan sertifikat sah; HSTS terpasang; HTTP dialihkan
- [ ] Semua *secret* berada di luar repositori; riwayat Git sudah diperiksa bebas *secret*
- [ ] `APP_DEBUG=false`; halaman galat tidak membocorkan detail
- [ ] 2FA aktif untuk semua akun admin
- [ ] Tidak ada akun/kata sandi bawaan yang masih aktif
- [ ] Semua kueri ke `residents`/`families`/`letter_requests`/`complaints` lewat lapis *repository* (diverifikasi analisis statis)
- [ ] Uji otorisasi (peran × endpoint × kepemilikan) lulus 100%
- [ ] `REVOKE UPDATE, DELETE ON audit_logs` sudah dijalankan dan diverifikasi
- [ ] `sql_mode` ketat aktif (05 §6); *binlog* aktif untuk RPO ≤ 1 jam
- [ ] `CHECK (id = 1)` pada `village_profile` aktif
- [ ] Enkripsi kolom NIK & nomor KK aktif dan teruji
- [ ] Penyaring log data sensitif aktif dan teruji
- [ ] Unggahan berkas: validasi *magic bytes*, pemindaian antivirus, *pre-signed URL*
- [ ] Header keamanan terpasang (diverifikasi dengan alat pemeriksa header)
- [ ] *Rate limit* aktif pada login, registrasi, pengaduan, verifikasi QR
- [ ] Cadangan otomatis berjalan **dan pemulihan sudah diuji nyata**
- [ ] Audit log aktif dan terbukti tidak dapat diubah dari aplikasi
- [ ] Data *staging* sudah dianonimkan; tidak ada data warga asli di non-produksi
- [ ] Halaman Kebijakan Privasi terbit dan disetujui Kepala Desa
- [ ] DPA (perjanjian pemrosesan data) dengan penyedia tertandatangani
- [ ] Penanggung jawab pelindungan data ditunjuk lewat SK
- [ ] Uji penetrasi selesai; temuan Critical/High tertutup
- [ ] Pemantauan & peringatan aktif dan terbukti mengirim notifikasi
- [ ] Prosedur tanggap insiden dikomunikasikan ke perangkat desa
- [ ] Pemindaian dependensi bersih dari Critical/High
