# 10 — Deployment & DevOps
**Proyek:** Sistem Informasi Desa (SID) · **Versi:** 2.0 · **Lingkup:** satu desa · **DB:** MySQL 8

---

## 1. Pilihan Model Penyebaran

| Model | Cocok untuk | Kelebihan | Kekurangan |
|-------|-------------|-----------|-----------|
| **A. VPS tunggal** (1 desa) — **dipilih** | Desa mandiri (KEP-001) | Termurah (± Rp 250–400 rb/bln), sederhana, isolasi mutlak | Tanpa redundansi; bergantung satu orang teknis |
| B. VPS terkelola multi-tenant | Penyedia melayani banyak desa | Biaya per desa rendah | Perlu retrofit multi-tenancy (05 §10) |
| **C. On-premise Diskominfo** | Kabupaten dengan pusat data sendiri | Kendali & kepatuhan penuh | Perlu SDM operasional kabupaten |
| **D. PaaS awan terkelola** | Tim kecil tanpa SysAdmin | Minim pemeliharaan infrastruktur | Biaya lebih tinggi; pastikan wilayah Indonesia (NFR-CMP-02) |

> **Keputusan:** Model **A** — satu instalasi untuk satu desa di VPS Indonesia
> (KEP-001). Topologi §2 di bawah ditulis untuk konfigurasi yang masih memisahkan
> basis data; untuk satu desa, menyatukan app + MySQL dalam **satu VPS 4 vCPU / 8 GB**
> juga memadai dan memangkas biaya ± 40% — lihat catatan di akhir §2.

---

## 2. Topologi Produksi

```
                            Internet
                                │
                    ┌───────────▼────────────┐
                    │ DNS + CDN/WAF          │  (Cloudflare atau setara)
                    │ · TLS edge             │
                    │ · DDoS & bot mitigation│
                    └───────────┬────────────┘
                                │
   ┌────────────────────────────▼─────────────────────────────┐
   │  VPS APLIKASI   (4 vCPU · 8 GB RAM · 100 GB SSD)         │
   │  ┌────────────────────────────────────────────────────┐  │
   │  │ Nginx — TLS, gzip/brotli, rate limit, aset statis  │  │
   │  ├──────────────────┬──────────────────┬──────────────┤  │
   │  │ app (PHP-FPM) ×2 │ worker (queue)×2 │ scheduler    │  │
   │  ├──────────────────┴──────────────────┴──────────────┤  │
   │  │ redis  ·  gotenberg (PDF)  ·  clamav               │  │
   │  └────────────────────────────────────────────────────┘  │
   └────────────┬───────────────────────────┬─────────────────┘
                │                           │
   ┌────────────▼──────────────┐  ┌─────────▼──────────────────┐
   │ VPS BASIS DATA            │  │ OBJECT STORAGE (S3)        │
   │ 2 vCPU · 8 GB · 100 GB    │  │ berkas unggahan & arsip PDF │
   │ MySQL 8 + binlog aktif    │  │ versioning aktif            │
   └────────────┬──────────────┘  └─────────┬──────────────────┘
                │                           │
   ┌────────────▼───────────────────────────▼──────────────────┐
   │ CADANGAN (penyedia/region BERBEDA)                        │
   │ · mysqldump/xtrabackup harian + binlog (RPO ≤ 1 jam)      │
   │ · sinkronisasi berkas harian · terenkripsi                │
   └───────────────────────────────────────────────────────────┘
```

**Mengapa basis data dipisah dari aplikasi:** memungkinkan pemutakhiran/*restart*
aplikasi tanpa risiko pada data, memudahkan penambahan *replica*, dan membatasi
dampak bila server aplikasi terkompromi.

> **Opsi hemat untuk satu desa:** dengan basis data < 1 GB (05 §8), app + MySQL dalam
> **satu VPS 4 vCPU / 8 GB** sudah memadai dan menurunkan biaya dari ± Rp 800 rb
> menjadi ± Rp 450 rb/bln. Yang **tidak boleh** dipangkas dalam opsi ini: cadangan ke
> penyedia berbeda, `binlog` aktif, dan `innodb_flush_log_at_trx_commit = 1`.
> Jalur peningkatan: pindahkan MySQL ke VPS sendiri tanpa perubahan kode (ubah
> `DB_HOST`), bila CPU atau I/O mulai menjadi hambatan.

---

## 3. Kontainerisasi

`docker-compose.prod.yml` (ringkas — kebijakan, bukan berkas final):

```yaml
services:
  nginx:
    image: nginx:1.27-alpine
    ports: ["80:80", "443:443"]
    volumes: [./docker/nginx:/etc/nginx/conf.d:ro, static:/var/www/public:ro]
    depends_on: [app]
    restart: unless-stopped

  app:
    image: ghcr.io/org/sid-desa:${APP_VERSION}
    env_file: .env.production
    deploy: { replicas: 2 }
    healthcheck:
      test: ["CMD", "php", "artisan", "health:check"]
      interval: 30s
      timeout: 5s
      retries: 3
    restart: unless-stopped

  worker:
    image: ghcr.io/org/sid-desa:${APP_VERSION}
    command: php artisan queue:work --queue=high,default,low --tries=3 --max-time=3600
    deploy: { replicas: 2 }
    restart: unless-stopped

  scheduler:
    image: ghcr.io/org/sid-desa:${APP_VERSION}
    command: php artisan schedule:work
    restart: unless-stopped

  redis:
    image: redis:7-alpine
    command: redis-server --requirepass ${REDIS_PASSWORD} --maxmemory 512mb
             --maxmemory-policy allkeys-lru --appendonly yes
    volumes: [redis-data:/data]
    restart: unless-stopped

  gotenberg:
    image: gotenberg/gotenberg:8
    restart: unless-stopped        # tidak diekspos ke internet

  clamav:
    image: clamav/clamav:stable
    volumes: [clamav-db:/var/lib/clamav]
    restart: unless-stopped

volumes: { redis-data:, clamav-db:, static: }
```

**Ketentuan kontainer:**
- Berjalan sebagai pengguna **non-root**; *filesystem* hanya-baca kecuali direktori
  sementara; `cap_drop: ALL`.
- Citra dibangun *multi-stage*; citra akhir **tanpa** alat *build* dan *dev dependencies*.
- Tag citra = *commit SHA* (**bukan** `latest`) agar *rollback* deterministik.
- Batas sumber daya (`cpus`, `mem_limit`) ditetapkan agar satu layanan tidak
  menghabiskan seluruh mesin.
- Hanya `nginx` yang mengekspos *port* ke internet.

---

## 4. CI/CD

### 4.1 Alur
```
PR dibuka
  → lint · analisis statis · uji unit · uji integrasi · uji otorisasi
  → pemindaian dependensi · deteksi secret
  → build citra percobaan
  → (semua lulus) layak di-merge
Merge ke main
  → build & push citra (tag = SHA)
  → deploy otomatis ke STAGING
  → migrasi staging · uji E2E · smoke test
  → (lulus) tandai "siap produksi"
Rilis (manual, tombol persetujuan)
  → deploy PRODUKSI dengan persetujuan 1 orang
  → migrasi (mundur-kompatibel) · smoke test · pantau 30 menit
  → gagal → rollback otomatis
```

### 4.2 Strategi rilis
- **Produksi:** *rolling update* dengan *health check* — satu *replica* diganti
  pada satu waktu. Tanpa waktu henti untuk rilis biasa.
- **Migrasi basis data:** **wajib mundur-kompatibel** (*expand → migrate → contract*):
  1. **Expand** — tambah kolom/tabel baru; kode lama tetap berjalan.
  2. **Deploy** — kode baru menulis ke keduanya.
  3. **Backfill** — isi data lama (asinkron, bertahap).
  4. **Contract** — rilis berikutnya menghapus kolom lama.

  Ini **tidak** boleh dilewati: menghapus kolom pada rilis yang sama dengan kodenya
  membuat *rollback* tidak mungkin.
- **Jendela rilis:** hari kerja 10.00–15.00 WIB — **jangan** Jumat sore atau menjelang
  hari libur, agar ada orang yang dapat menangani bila ada masalah.
- **Rollback:** jalankan ulang citra versi sebelumnya (satu perintah). Target ≤ 10 menit.
  **Catatan:** *rollback* kode mudah, *rollback* migrasi tidak — karena itu aturan
  mundur-kompatibel di atas bersifat mutlak.

### 4.3 Perintah operasional
```bash
# Rilis
./ops/deploy.sh production <commit-sha>

# Rollback ke versi sebelumnya
./ops/rollback.sh production

# Migrasi manual (bila pipeline dilewati)
docker compose exec app php artisan migrate --force

# Mode pemeliharaan
docker compose exec app php artisan down --render=maintenance --retry=60
docker compose exec app php artisan up
```

---

## 5. Konfigurasi & Secret

| Jenis | Tempat |
|-------|--------|
| Konfigurasi infrastruktur | Variabel lingkungan (`.env.production`, izin `600`, pemilik `deploy`) |
| *Secret* | *Secret manager* (Vault/Infisical) atau variabel lingkungan terenkripsi; **tidak pernah** di repositori |
| Aturan bisnis per desa | Tabel `settings` (dapat diubah dari UI tanpa rilis) |
| *Feature flag* | Tabel `settings` + kelas `Feature` |

**Rotasi:** kunci aplikasi tahunan · kata sandi basis data tahunan · token API saat
pergantian personel · **segera** setelah insiden atau kepergian anggota tim yang
memegang akses.

**Variabel lingkungan minimum:**
```
APP_ENV=production
APP_DEBUG=false                 # wajib false
APP_KEY=<32 byte acak>
APP_URL=https://sid.go.id
DB_CONNECTION=mysql
DB_HOST / DB_PORT=3306 / DB_DATABASE=sid_desa / DB_USERNAME / DB_PASSWORD
MYSQL_ATTR_SSL_CA=/etc/ssl/certs/ca-certificates.crt   # TLS ke MySQL wajib
REDIS_HOST / REDIS_PASSWORD
S3_ENDPOINT / S3_BUCKET / S3_KEY / S3_SECRET / S3_REGION
PII_ENCRYPTION_KEY=<kunci enkripsi kolom>
PII_HASH_PEPPER=<pepper HMAC>
MAIL_* / WHATSAPP_*
GOTENBERG_URL=http://gotenberg:3000
SENTRY_DSN=
```

---

## 6. Cadangan & Pemulihan Bencana

### 6.1 Jadwal
| Apa | Frekuensi | Retensi | Lokasi |
|-----|-----------|---------|--------|
| Basis data (penuh) | Harian 02.00 WIB | 7 harian · 4 mingguan · 12 bulanan | Penyedia/region berbeda |
| Basis data (**binlog**) | Berkelanjutan | 7 hari | Penyedia berbeda |
| Berkas (object storage) | Harian | 30 hari + *versioning* | Bucket berbeda |
| Konfigurasi & *secret* | Saat berubah | 5 versi | Penyimpanan terenkripsi |

**Semua cadangan terenkripsi** sebelum keluar dari server. Kunci cadangan **tidak**
disimpan bersama data cadangan.

### 6.2 Skrip cadangan (inti)
```bash
#!/usr/bin/env bash
set -euo pipefail
TS=$(date +%Y%m%d-%H%M%S)
DUMP="/tmp/sid-${TS}.sql.gz"

# --single-transaction: konsisten tanpa mengunci tabel (InnoDB)
# --source-data=2    : catat posisi binlog → pemulihan titik-waktu
# --routines --events --triggers : ikut sertakan objek selain tabel
mysqldump \
  --defaults-extra-file=/etc/mysql/backup.cnf \
  --single-transaction --quick --source-data=2 \
  --routines --events --triggers \
  --hex-blob \
  "$DB_DATABASE" | gzip -9 > "$DUMP"

age -r "$BACKUP_PUBLIC_KEY" -o "${DUMP}.age" "$DUMP"
rclone copy "${DUMP}.age" "backup:sid-backups/db/"

# Verifikasi: cadangan yang tidak pernah diuji bukan cadangan
gzip -t "$DUMP" || { echo "DUMP RUSAK"; exit 1; }
zcat "$DUMP" | tail -5 | grep -q "Dump completed" \
  || { echo "DUMP TIDAK LENGKAP"; exit 1; }
shred -u "$DUMP" "${DUMP}.age"

curl -fsS "$HEALTHCHECK_PING_URL"   # gagal mengirim ping → memicu peringatan
```
> **`--hex-blob` wajib:** kolom NIK/no. KK bertipe `VARBINARY` (data terenkripsi).
> Tanpa flag ini, isinya bisa rusak saat di-*dump* dan dipulihkan.
>
> **Pemeriksaan "Dump completed" wajib:** `mysqldump` dapat berhenti di tengah dan
> tetap mengembalikan kode keluar 0 melalui *pipe*. Tanpa pemeriksaan ini, Anda
> menyimpan cadangan terpotong selama berbulan-bulan tanpa menyadarinya.
>
> Untuk basis data > 5 GB, ganti ke **Percona XtraBackup** (cadangan fisik, pemulihan
> jauh lebih cepat). Pada skala satu desa (< 1 GB), `mysqldump` sudah memadai.

### 6.3 Latihan pemulihan (kuartalan, **wajib**)
1. Sediakan server bersih.
2. Pulihkan cadangan terbaru.
3. Verifikasi: jumlah baris per tabel inti, 10 PDF surat dapat dibuka dan *hash* cocok,
   login berfungsi, statistik benar.
4. Catat **waktu nyata** yang dibutuhkan → bandingkan dengan RTO 4 jam.
5. Perbarui *runbook* bila ada langkah yang ternyata tidak sesuai.

> Cadangan yang belum pernah dipulihkan harus dianggap **tidak ada**.

---

## 7. Pemantauan & Observabilitas

| Lapis | Alat | Yang dipantau |
|-------|------|---------------|
| Ketersediaan | Uptime Kuma / Healthchecks.io | `/health` tiap menit dari luar; sertifikat TLS (peringatan 30 hari sebelum kedaluwarsa) |
| Infrastruktur | Prometheus + node_exporter + Grafana | CPU, RAM, disk, I/O, jaringan |
| Basis data | mysqld_exporter | Koneksi, *lock*/deadlock InnoDB, *slow query* (> 1 s), rasio *buffer pool* hit, lag replikasi, keterisian disk, **status binlog** |
| Aplikasi | Metrik kustom | Rate/Errors/Duration per endpoint, panjang *queue*, pekerjaan gagal, durasi render PDF |
| Galat | Sentry / GlitchTip | *Exception* dengan `correlation_id`; **penyaring data pribadi aktif** |
| Log | Log JSON + Loki/`journald` | Terpusat, dapat dicari, retensi 90 hari |
| Bisnis | Dashboard | Surat terbit/hari, pengajuan melewati SLA, pengaduan terbuka, pendaftaran warga menunggu verifikasi |

**Endpoint kesehatan:**
```
GET /health        → 200 bila proses hidup (untuk load balancer)
GET /health/ready  → memeriksa basis data, Redis, S3, Gotenberg
```
`/health/ready` **tidak** boleh diekspos ke publik tanpa autentikasi — isinya
membocorkan topologi internal.

**Peringatan (lihat juga dokumen 08 §8):**
| Tingkat | Kanal | Contoh |
|---------|-------|--------|
| Kritis | Telepon/WA + email | Situs mati, basis data tidak dapat dihubungi, cadangan gagal, dugaan kebocoran data |
| Peringatan | WA/email | Disk > 80%, galat 5xx > 1%, *queue* menumpuk, sertifikat ≤ 14 hari |
| Info | Email harian | Ringkasan rilis, statistik penggunaan |

**Aturan anti-kelelahan peringatan:** setiap peringatan harus **dapat ditindaklanjuti**.
Peringatan yang berulang tanpa tindakan harus dihapus atau ambangnya diperbaiki —
peringatan yang diabaikan lebih buruk daripada tidak ada peringatan.

---

## 8. Pengerasan Server

| Area | Ketentuan |
|------|-----------|
| SSH | Hanya kunci (kata sandi dimatikan), *port* non-standar, login root dimatikan, fail2ban aktif |
| Firewall | Tolak semua masukan kecuali 80/443 (publik) dan SSH (dari IP terdaftar). Basis data **hanya** menerima dari IP server aplikasi |
| Pembaruan | Pembaruan keamanan otomatis; *reboot* terjadwal dengan pemberitahuan |
| Pengguna | Satu pengguna `deploy` tanpa sudo tanpa kata sandi; tidak ada akun bersama |
| Pemantauan integritas | AIDE/Tripwire pada berkas konfigurasi |
| Waktu | NTP tersinkronisasi (penting agar audit log dapat dipercaya) |
| Log | Dikirim keluar server agar tetap ada bila server terkompromi |

---

## 9. Runbook Operasional (ringkas)

Lokasi: `ops/runbook/`. Setiap *runbook* memuat gejala, diagnosis, tindakan, dan eskalasi.

| Situasi | Langkah pertama |
|---------|-----------------|
| Situs tidak dapat diakses | Cek `/health` → cek kontainer → cek Nginx → cek basis data → cek disk penuh |
| Basis data lambat | Cek *slow query log* → `SHOW ENGINE INNODB STATUS` (deadlock/lock wait) → `SHOW PROCESSLIST` → cek rasio *buffer pool* hit |
| *Queue* menumpuk | Cek pekerjaan gagal → cek layanan Gotenberg/SMTP → tambah *worker* |
| Disk penuh | Cek log, berkas sementara, PDF lama → rotasi log → tambah volume |
| Surat gagal dibuat | Cek Gotenberg → cek galat template → cek S3 |
| Dugaan kebocoran data | **Ikuti dokumen 08 §9 — jangan bertindak sendiri** |
| Perlu *rollback* | `./ops/rollback.sh production` → verifikasi → beri tahu PO |
| Permintaan pemulihan data | Verifikasi wewenang pemohon → pulihkan ke lingkungan terpisah → ambil data yang diperlukan |

---

## 10. Serah Terima (Handover)

Agar desa/kabupaten tidak terkunci pada satu pihak, serahkan:
- [ ] Kode sumber lengkap + riwayat Git
- [ ] Kredensial semua layanan (lewat *password manager* bersama, bukan WhatsApp)
- [ ] Dokumen 01–12 versi terakhir
- [ ] *Runbook* operasional & skrip rilis/cadangan
- [ ] Rekaman pelatihan administrator teknis
- [ ] Bukti **latihan pemulihan cadangan** yang berhasil
- [ ] Daftar komponen pihak ketiga, lisensinya, dan tanggal berakhir langganan
- [ ] Rencana dukungan: siapa dihubungi, jam berapa, melalui apa, berapa lama

---

## 11. Perkiraan Biaya Operasional (indikatif)

| Komponen | Model B (multi-tenant, ±20 desa) | **Model A (1 desa) — dipilih** |
|----------|----------------------------------|------------------|
| VPS aplikasi | Rp 400–700 rb/bln | Rp 250–400 rb/bln |
| VPS basis data | Rp 400–700 rb/bln | Rp 0 (digabung) atau Rp 250–400 rb |
| Object storage | Rp 50–150 rb/bln | Rp 20–50 rb/bln |
| Cadangan luar | Rp 50–100 rb/bln | Rp 20–50 rb/bln |
| CDN/WAF | Rp 0 (paket gratis) | Rp 0 |
| Domain `desa.id` | Rp 50–100 rb/tahun per desa | sama |
| Pemantauan galat | Rp 0 (*self-hosted*) | Rp 0 |
| WhatsApp gateway | Rp 100–500 rb/bln (per pemakaian) | sesuai pemakaian |
| **Total** | **± Rp 1–2 jt/bln** (≈ Rp 50–100 rb/desa/bln) | **± Rp 400–600 rb/bln** ← **model yang dipilih** |

> Angka indikatif per Oktober 2026 untuk penyedia dalam negeri; **wajib diverifikasi
> ulang** saat penyusunan anggaran. Belum termasuk biaya pengembangan, pemeliharaan
> aplikasi, dan uji penetrasi (lihat dokumen 11).
