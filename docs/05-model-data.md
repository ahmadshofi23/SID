# 05 — Model Data & ERD
**Proyek:** Sistem Informasi Desa (SID) · **Versi:** 2.0
**Target DBMS:** **MySQL 8.0** (InnoDB · `utf8mb4` · `utf8mb4_0900_ai_ci`)
**Lingkup:** **Satu desa / single-tenant** (KEP-001, KEP-002)

> **Perubahan dari v1.0:** target basis data berubah dari PostgreSQL ke MySQL 8, dan
> lingkup dari multi-tenant ke satu desa. Akibatnya **kolom `tenant_id` dihapus dari
> seluruh tabel** dan tabel `tenants` diganti `village_profile` (satu baris).
> Catatan perbedaan MySQL vs PostgreSQL ada di §9; rencana retrofit multi-desa di §10.
> Versi PostgreSQL/multi-tenant tidak lagi berlaku sebagai acuan implementasi.

---

## 1. Prinsip Pemodelan

1. **Kunci utama `CHAR(36)` UUID v7** — aman dipakai di URL (tidak membocorkan jumlah
   data), berurutan menurut waktu sehingga indeks B-tree tetap efisien.
   Pengecualian: `audit_logs` memakai `BIGINT UNSIGNED AUTO_INCREMENT` (§3.6).
2. **Tidak ada `tenant_id`.** Satu basis data = satu desa. Identitas desa ada di
   `village_profile` (satu baris, `id = 1`).
3. **Tidak ada teks bebas untuk data berkode** — semua memakai tabel referensi
   (`ref_*`) agar statistik dapat diandalkan (prinsip Satu Data Indonesia).
4. **Soft delete** (`deleted_at`) pada entitas yang punya nilai historis.
5. **Jejak audit** (`created_by`, `updated_by`, `created_at`, `updated_at`) di semua
   tabel operasional.
6. **Snapshot historis** — surat terbit menyimpan salinan data pemohon pada saat
   terbit, **tidak** mengandalkan `JOIN` ke data penduduk yang bisa berubah.
7. **Nama tabel & kolom** bahasa Inggris (konsistensi teknis); **nilai referensi**
   bahasa Indonesia (konsistensi domain).
8. **Keunikan bersyarat lewat kolom *generated*** — teknik pengganti *partial unique
   index* PostgreSQL yang tidak ada di MySQL (§2.2).

### Konvensi MySQL yang wajib dipakai
```sql
-- Setiap CREATE TABLE diakhiri dengan:
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
```
| Aturan | Alasan |
|--------|--------|
| `utf8mb4` (bukan `utf8`) | `utf8` MySQL hanya 3 byte — nama dengan karakter tertentu & emoji rusak |
| Selalu InnoDB | Transaksi & *foreign key*; MyISAM **dilarang** |
| `DATETIME` disimpan **UTC** | Konversi ke WIB dilakukan aplikasi (NFR) |
| `DECIMAL` untuk uang, **bukan** `FLOAT` | Mencegah galat pembulatan |
| `sql_mode` wajib memuat `STRICT_TRANS_TABLES`, `NO_ZERO_DATE`, `ONLY_FULL_GROUP_BY` | Tanpa ini MySQL menerima data tidak valid secara diam-diam |

---

## 2. ERD

### 2.1 Gambaran umum

```
┌────────────────────┐      ┌──────────────┐
│  village_profile   │      │  officials   │  perangkat desa
│  (1 baris: id=1)   │      │  (struktur & │  → juga sumber
│  identitas · kop   │      │   penanda    │    daftar penanda
└────────────────────┘      │   tangan)    │    tangan surat
                            └──────┬───────┘
┌───────────────┐                  │
│    regions    │◄──────┐          │
│ (dusun/RW/RT) │       │   ┌──────▼───────┐    ┌─────────────────┐
│  self-parent  │       │   │    users     │──► user_roles ──►│ roles │
└───────┬───────┘       │   └──────┬───────┘    └─────────────────┘
        │ 1..*          │          │ 0..1                │
        ▼               │          │              role_permissions
┌───────────────┐       │          │                     │
│   families    │◄──────┼──┐       │              ┌──────▼───────┐
│ (kartu kelg.) │ 1..*  │  │       │              │ permissions  │
└───────────────┘       │  │       │              └──────────────┘
        ▲               │  │       │
        │ family_id     │  │       │
┌───────┴───────────────┴──┴───┐   │
│         residents            │   │
│  region_id · family_id       │   │
└──┬────────────────────────┬──┘   │
   │                        │      │
   ▼                        ▼      ▼
┌────────────────────┐  ┌──────────────────────┐
│ resident_mutations │  │   letter_requests    │
│ lahir/mati/pindah  │  │  (pengajuan surat)   │
└────────────────────┘  └───┬──────────────┬───┘
                            │              │
            ┌───────────────┘              ▼
            ▼                   ┌─────────────────────┐
  ┌──────────────────┐          │ letter_request_logs │
  │   letter_types   │          │  (riwayat status)   │
  │ + form_schema    │          └─────────────────────┘
  │ + template_html  │
  │ + approval_flow  │          ┌──────────────────┐
  └────────┬─────────┘          │     letters      │
           │                    │ (surat TERBIT:   │
           ▼                    │  snapshot + PDF) │
  ┌──────────────────┐          └──────────────────┘
  │ letter_counters  │  penomoran (lock FOR UPDATE)
  └──────────────────┘

MODUL LAIN
┌───────────┐ ┌──────────────────┐ ┌───────┐ ┌────────┐ ┌──────────────┐
│complaints │ │complaint_actions │ │ posts │ │ pages  │ │ attachments  │
└───────────┘ └──────────────────┘ └───────┘ └────────┘ └──────────────┘
┌────────────┐ ┌──────────┐ ┌────────────┐ ┌──────────────────┐
│ audit_logs │ │ settings │ │ ref_* (12) │ │ population_stats │ ← tabel ringkasan
└────────────┘ └──────────┘ └────────────┘ └──────────────────┘
FASE 2: budgets · budget_items · budget_realizations · assets · asset_movements
        · aid_programs · aid_recipients
```

### 2.2 Teknik Keunikan Bersyarat (pengganti *partial unique index*)

MySQL tidak mendukung `CREATE UNIQUE INDEX ... WHERE <kondisi>`. Dua aturan bisnis
kita membutuhkannya:

| Aturan | Kebutuhan |
|--------|-----------|
| RULE-01 | NIK unik **hanya di antara penduduk yang belum dihapus** |
| RULE-02 | Tepat satu kepala keluarga **per KK, di antara yang belum dihapus** |

**Solusi:** kolom *generated* `STORED` yang bernilai `NULL` bila baris tidak memenuhi
syarat, lalu UNIQUE di atasnya. MySQL mengizinkan **banyak `NULL`** pada indeks UNIQUE,
sehingga baris yang tidak memenuhi syarat tidak saling bertabrakan.

```sql
-- NIK unik hanya untuk penduduk aktif (belum di-soft-delete)
nik_hash_active CHAR(64)
    GENERATED ALWAYS AS (IF(deleted_at IS NULL, nik_hash, NULL)) STORED,
UNIQUE KEY uq_residents_nik_active (nik_hash_active)

-- Tepat satu kepala keluarga per KK
family_head_key CHAR(36)
    GENERATED ALWAYS AS (
        IF(family_relation_code = 'kepala_keluarga' AND deleted_at IS NULL,
           family_id, NULL)
    ) STORED,
UNIQUE KEY uq_families_single_head (family_head_key)
```
Teknik ini **ditegakkan oleh basis data**, bukan oleh kode aplikasi — jadi tetap aman
walau ada bug di aplikasi atau ada perbaikan data manual lewat SQL.
Wajib ada uji integrasi untuk keduanya (09 §4.1).

---

## 3. Definisi Tabel Inti (DDL MySQL 8)

### 3.1 Identitas Desa, Wilayah, Perangkat

```sql
-- Satu baris saja (id = 1). Menggantikan tabel `tenants` versi multi-tenant.
CREATE TABLE village_profile (
    id              TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
    code            VARCHAR(20)  NOT NULL,          -- kode desa Kemendagri
    name            VARCHAR(150) NOT NULL,          -- "Sukamaju"
    district        VARCHAR(100) NOT NULL,          -- kecamatan
    regency         VARCHAR(100) NOT NULL,          -- kabupaten/kota
    province        VARCHAR(100) NOT NULL,
    postal_code     VARCHAR(10)  NULL,
    address         VARCHAR(255) NULL,              -- alamat kantor desa
    phone           VARCHAR(30)  NULL,
    email           VARCHAR(150) NULL,
    website         VARCHAR(150) NULL,
    logo_path       VARCHAR(255) NULL,
    letterhead_path VARCHAR(255) NULL,
    stamp_path      VARCHAR(255) NULL,              -- gambar stempel/cap
    timezone        VARCHAR(40)  NOT NULL DEFAULT 'Asia/Jakarta',
    latitude        DECIMAL(10,7) NULL,
    longitude       DECIMAL(10,7) NULL,
    history         TEXT NULL,                      -- sejarah desa (portal)
    vision          TEXT NULL,
    mission         TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                             ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_village_single_row CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```
> `CHECK (id = 1)` memastikan tabel ini tidak pernah berisi lebih dari satu desa —
> pengaman murah terhadap kesalahan konfigurasi.

```sql
-- Hierarki wilayah: Dusun → RW → RT (rekursif)
CREATE TABLE regions (
    id         CHAR(36)    NOT NULL PRIMARY KEY,
    parent_id  CHAR(36)    NULL,
    level      ENUM('dusun','rw','rt') NOT NULL,
    code       VARCHAR(20) NOT NULL,          -- "001"
    name       VARCHAR(100) NOT NULL,         -- "Dusun Krajan" / "RT 003"
    head_name  VARCHAR(150) NULL,             -- kepala dusun / ketua RW / ketua RT
    head_phone VARCHAR(30)  NULL,
    path       VARCHAR(255) NOT NULL,         -- 'krajan/rw02/rt003' (materialized path)
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_regions_code (parent_id, code),
    KEY idx_regions_level (level),
    KEY idx_regions_path (path),
    CONSTRAINT fk_regions_parent FOREIGN KEY (parent_id)
        REFERENCES regions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```
> **Mengapa `path`:** mendapatkan "semua penduduk di satu dusun" tanpa rekursi —
> `WHERE r.path LIKE 'krajan/%'` memakai indeks. MySQL 8 juga mendukung CTE rekursif,
> tetapi `path` lebih sederhana dan lebih cepat untuk kasus ini. Kolom ini
> **dihitung ulang** oleh aplikasi setiap kali wilayah dipindahkan.

```sql
-- Perangkat desa: untuk struktur organisasi di portal DAN daftar penanda tangan surat
CREATE TABLE officials (
    id              CHAR(36)     NOT NULL PRIMARY KEY,
    user_id         CHAR(36)     NULL,           -- bila punya akun sistem
    name            VARCHAR(150) NOT NULL,
    position        VARCHAR(100) NOT NULL,       -- "Kepala Desa", "Sekretaris Desa"
    position_code   VARCHAR(40)  NOT NULL,       -- kepala_desa, sekretaris_desa, kasi_...
    nip             VARCHAR(30)  NULL,
    niap            VARCHAR(30)  NULL,           -- nomor induk aparatur desa
    region_id       CHAR(36)     NULL,           -- untuk kepala dusun
    photo_path      VARCHAR(255) NULL,
    signature_path  VARCHAR(255) NULL,           -- gambar tanda tangan (opsional)
    term_start      DATE NULL,
    term_end        DATE NULL,
    can_sign_letter TINYINT(1) NOT NULL DEFAULT 0,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    sort_order      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_officials_active (is_active, sort_order),
    KEY idx_officials_signer (can_sign_letter, is_active),
    CONSTRAINT fk_officials_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```
> **Mengapa `officials` dipisah dari `users`:** tidak semua perangkat desa punya akun
> sistem (mis. perangkat yang sudah tidak menjabat tetapi namanya masih tercantum di
> surat lama), dan struktur organisasi perlu tampil di portal publik tanpa membuka
> tabel pengguna. `signature_path` hanya gambar tanda tangan untuk dicetak — **bukan**
> tanda tangan elektronik tersertifikasi (lihat 04 §10 butir 3).

### 3.2 Kependudukan

```sql
CREATE TABLE families (
    id              CHAR(36)     NOT NULL PRIMARY KEY,
    kk_number_enc   VARBINARY(255) NOT NULL,       -- nomor KK, terenkripsi (ADR-004)
    kk_number_hash  CHAR(64)     NOT NULL,         -- HMAC-SHA256, untuk cari & unik
    kk_number_masked VARCHAR(20) NOT NULL,         -- "3201••••••••0001" untuk tampilan
    region_id       CHAR(36)     NULL,             -- RT tempat tinggal
    address         VARCHAR(255) NOT NULL,
    rt              VARCHAR(10)  NULL,
    rw              VARCHAR(10)  NULL,
    postal_code     VARCHAR(10)  NULL,
    welfare_status_code  VARCHAR(30) NULL,
    house_ownership_code VARCHAR(30) NULL,
    created_by CHAR(36) NULL,
    updated_by CHAR(36) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,

    kk_hash_active CHAR(64)
        GENERATED ALWAYS AS (IF(deleted_at IS NULL, kk_number_hash, NULL)) STORED,
    UNIQUE KEY uq_families_kk_active (kk_hash_active),
    KEY idx_families_region (region_id),
    KEY idx_families_deleted (deleted_at),
    CONSTRAINT fk_families_region FOREIGN KEY (region_id)
        REFERENCES regions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```

```sql
CREATE TABLE residents (
    id              CHAR(36) NOT NULL PRIMARY KEY,
    family_id       CHAR(36) NULL,
    region_id       CHAR(36) NULL,

    -- Identitas (terenkripsi di lapis aplikasi — ADR-004)
    nik_enc         VARBINARY(255) NOT NULL,
    nik_hash        CHAR(64)     NOT NULL,         -- HMAC untuk cari persis & unik
    nik_masked      VARCHAR(20)  NOT NULL,         -- "3201••••••••1234"
    full_name       VARCHAR(150) NOT NULL,
    full_name_norm  VARCHAR(150) NOT NULL,         -- huruf kecil, tanpa gelar/tanda baca
    birth_place     VARCHAR(100) NULL,
    birth_date      DATE         NOT NULL,
    gender          ENUM('L','P') NOT NULL,
    blood_type_code VARCHAR(10)  NULL,

    -- Data berkode (→ tabel ref_*)
    religion_code        VARCHAR(20) NULL,
    marital_status_code  VARCHAR(20) NULL,
    family_relation_code VARCHAR(30) NOT NULL,     -- kepala_keluarga|istri|anak|...
    education_code       VARCHAR(20) NULL,
    occupation_code      VARCHAR(30) NULL,
    citizenship          VARCHAR(20) NOT NULL DEFAULT 'WNI',

    -- Orang tua (untuk akta & verifikasi silsilah)
    father_name     VARCHAR(150) NULL,
    mother_name     VARCHAR(150) NULL,
    father_nik_hash CHAR(64)     NULL,
    mother_nik_hash CHAR(64)     NULL,

    -- Kontak & domisili
    address          VARCHAR(255) NULL,
    phone            VARCHAR(30)  NULL,
    email            VARCHAR(150) NULL,
    domicile_address VARCHAR(255) NULL,            -- bila berbeda dari alamat KK

    -- Status kependudukan
    status ENUM('aktif','meninggal','pindah_keluar','hilang','tidak_diketahui')
           NOT NULL DEFAULT 'aktif',
    status_changed_at DATETIME NULL,

    -- Atribut sosial (untuk program bantuan)
    disability_code VARCHAR(30) NULL,
    is_poor         TINYINT(1)  NULL,
    photo_path      VARCHAR(255) NULL,
    notes           TEXT NULL,

    created_by CHAR(36) NULL,
    updated_by CHAR(36) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,

    -- RULE-01: NIK unik di antara penduduk yang belum dihapus (§2.2)
    nik_hash_active CHAR(64)
        GENERATED ALWAYS AS (IF(deleted_at IS NULL, nik_hash, NULL)) STORED,
    UNIQUE KEY uq_residents_nik_active (nik_hash_active),

    -- RULE-02: tepat satu kepala keluarga per KK (§2.2)
    family_head_key CHAR(36)
        GENERATED ALWAYS AS (
            IF(family_relation_code = 'kepala_keluarga' AND deleted_at IS NULL,
               family_id, NULL)
        ) STORED,
    UNIQUE KEY uq_families_single_head (family_head_key),

    KEY idx_residents_nik_hash (nik_hash),
    KEY idx_residents_name     (full_name_norm),
    KEY idx_residents_region   (region_id, status, deleted_at),
    KEY idx_residents_family   (family_id, deleted_at),
    KEY idx_residents_birth    (birth_date),
    -- Indeks PENUTUP untuk halaman Laporan (KEP-019) — enam kolom terakhir
    -- ada supaya rekap per wilayah & per kategori tidak perlu membuka indeks
    -- berkluster sama sekali. Lihat catatan di bawah blok ini.
    KEY idx_residents_status   (status, deleted_at, region_id, gender,
                                religion_code, education_code,
                                occupation_code, marital_status_code),
    FULLTEXT KEY ft_residents_name (full_name),

    -- RESTRICT (bukan SET NULL): MySQL melarang aksi referensial
    -- CASCADE/SET NULL/SET DEFAULT pada kolom yang menjadi BASIS kolom
    -- generated — di sini `family_id` adalah basis `family_head_key`.
    -- Konsisten dengan RULE-08: keluarga hanya di-soft-delete.
    CONSTRAINT fk_residents_family FOREIGN KEY (family_id)
        REFERENCES families(id) ON DELETE RESTRICT,
    CONSTRAINT fk_residents_region FOREIGN KEY (region_id)
        REFERENCES regions(id) ON DELETE SET NULL,
    -- CATATAN: CHECK (birth_date <= CURRENT_DATE) TIDAK DAPAT DIBUAT.
    -- MySQL dan PostgreSQL sama-sama melarang fungsi non-deterministik di
    -- dalam CHECK (hasilnya berubah seiring waktu). Validasi "tanggal lahir
    -- tidak boleh di masa depan" ditegakkan di lapis aplikasi (FR-PDK-02).
    CONSTRAINT chk_residents_citizenship CHECK (citizenship IN ('WNI','WNA'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```

> **Mengapa `idx_residents_status` selebar itu (KEP-019).** Halaman Laporan
> menampilkan rekap penduduk per wilayah dan per lima kategori. Seluruhnya
> menyaring `status = 'aktif' AND deleted_at IS NULL`, lalu membaca **satu
> kolom lain** per baris. Dengan indeks yang hanya memuat dua kolom penyaring,
> setiap rekap harus mengambil kolomnya dari indeks berkluster — 19.600
> pencarian baris acak per rekap. Terukur pada 20.000 penduduk, yaitu volume
> sasaran resmi proyek (NFR-PERF-07): `ReportService::population()` **755 ms
> → 43 ms**, dan halaman `/laporan` 495 ms → 66 ms waktu basis data. Angka 755
> ms itu melewati NFR-PERF-01 (p95 baca ≤ 500 ms) sendirian; halaman itu lolos
> sampai Sprint 7 hanya karena basis data pengembangan berisi 500 penduduk,
> bukan 20.000.
>
> Indeks penutup **terpisah** sudah dicoba lebih dulu dan ditolak karena
> diukur: pengoptimasi MySQL tetap memilih `idx_residents_status` yang lebih
> sempit, sehingga indeks baru hanya terpakai dengan `FORCE INDEX` — petunjuk
> indeks di dalam Eloquent adalah janji yang patah tanpa suara pada versi MySQL
> berikutnya. Dengan melebarkan indeks yang **sudah** dipilih, tidak ada
> pilihan yang perlu dipaksakan. Harganya ± 110 byte per baris pada setiap
> penulisan: yang ditukar adalah penulisan yang tidak ada yang menungguinya
> dengan pembacaan yang ditunggui di depan layar.

**Strategi pencarian nama (konsekuensi KEP-002):**
```sql
-- 1) Prefiks — memakai indeks, paling cepat. Dipakai untuk autocomplete.
WHERE full_name_norm LIKE 'siti%'

-- 2) Substring — pemindaian, tetapi di ~5.000 baris praktis seketika (< 10 ms).
WHERE full_name_norm LIKE '%aminah%'

-- 3) FULLTEXT — pencocokan per kata, berguna untuk nama majemuk.
WHERE MATCH(full_name) AGAINST('siti aminah' IN NATURAL LANGUAGE MODE)
```
Aplikasi mencoba (1) lalu (2). **Tidak ada toleransi salah ketik** — ini
konsekuensi yang diterima di KEP-002 §2. Mitigasi di UI: pencarian juga menerima
NIK/nomor KK persis, dan hasil menampilkan nama + umur + dusun agar petugas yakin
memilih orang yang benar.

```sql
-- Mutasi penduduk (BR-03)
CREATE TABLE resident_mutations (
    id             CHAR(36) NOT NULL PRIMARY KEY,
    resident_id    CHAR(36) NOT NULL,
    type ENUM('lahir','mati','pindah_datang','pindah_keluar','perubahan_data') NOT NULL,
    mutation_date  DATE NOT NULL,
    reason         TEXT NULL,
    -- Kelahiran
    birth_weight   DECIMAL(5,2) NULL,
    birth_length   DECIMAL(5,2) NULL,
    birth_helper   VARCHAR(50)  NULL,
    -- Kematian
    death_cause    VARCHAR(100) NULL,
    death_place    VARCHAR(150) NULL,
    -- Pindah
    origin_address      VARCHAR(255) NULL,
    destination_address VARCHAR(255) NULL,
    -- Perubahan data: simpan selisih nilai
    changes        JSON NULL,
    document_no    VARCHAR(100) NULL,              -- no. surat/akta pendukung
    letter_id      CHAR(36) NULL,                  -- surat yang memicu mutasi (dok. 13)
    reported_by    VARCHAR(150) NULL,
    created_by     CHAR(36) NOT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_mutations_date     (mutation_date),
    KEY idx_mutations_resident (resident_id, mutation_date),
    KEY idx_mutations_type     (type, mutation_date),
    CONSTRAINT fk_mutations_resident FOREIGN KEY (resident_id)
        REFERENCES residents(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```
> `letter_id` menautkan mutasi ke surat yang memicunya — mendukung integrasi
> SKL/SKM/SKP di dokumen 13 (§5.4, §5.5, §5.6) dan memudahkan audit.

### 3.3 Persuratan

```sql
CREATE TABLE letter_types (
    id               CHAR(36)     NOT NULL PRIMARY KEY,
    code             VARCHAR(30)  NOT NULL,        -- "SKTM", "SKD", "SKU"
    name             VARCHAR(150) NOT NULL,        -- "Surat Keterangan Tidak Mampu"
    title_on_letter  VARCHAR(150) NOT NULL,        -- "SURAT KETERANGAN TIDAK MAMPU"
    classification_code VARCHAR(20) NULL,          -- kode klasifikasi arsip (dok. 13 §3)
    description      TEXT NULL,
    requirements     JSON NOT NULL DEFAULT (JSON_ARRAY()),
    form_schema      JSON NOT NULL DEFAULT (JSON_OBJECT()),   -- ADR-002
    template_html    MEDIUMTEXT NOT NULL,          -- ISI POKOK saja (dok. 13 §1)
    number_pattern   VARCHAR(150) NOT NULL
        DEFAULT '{urut}/{kode}/{kode_desa}/{bulan_romawi}/{tahun}',
    approval_flow    JSON NOT NULL DEFAULT (JSON_ARRAY()),
    sla_days         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    validity_months  SMALLINT UNSIGNED NULL,       -- masa berlaku surat
    fee              DECIMAL(12,2) NOT NULL DEFAULT 0,
    requires_field_check TINYINT(1) NOT NULL DEFAULT 0,  -- SKPT: wajib cek lapangan
    warning_note     TEXT NULL,                    -- peringatan di layar persetujuan
    is_public        TINYINT(1) NOT NULL DEFAULT 1,  -- dapat diajukan warga daring?
    is_active        TINYINT(1) NOT NULL DEFAULT 1,
    sort_order       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_by CHAR(36) NULL,
    updated_by CHAR(36) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    code_active VARCHAR(30)
        GENERATED ALWAYS AS (IF(deleted_at IS NULL, code, NULL)) STORED,
    UNIQUE KEY uq_letter_types_code (code_active),
    KEY idx_letter_types_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```

```sql
CREATE TABLE letter_requests (
    id              CHAR(36)    NOT NULL PRIMARY KEY,
    letter_type_id  CHAR(36)    NOT NULL,
    ticket_number   VARCHAR(40) NOT NULL,          -- SRT-2026-000123
    resident_id     CHAR(36)    NOT NULL,          -- pemohon
    requested_by    CHAR(36)    NULL,              -- akun pengaju (warga/petugas)
    channel         ENUM('online','walk_in') NOT NULL DEFAULT 'online',
    purpose         VARCHAR(255) NULL,
    form_data       JSON NOT NULL DEFAULT (JSON_OBJECT()),
    status ENUM('draft','diajukan','perlu_revisi','diverifikasi',
                'diterbitkan','ditolak','dibatalkan') NOT NULL DEFAULT 'draft',
    current_step    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    submitted_at    DATETIME NULL,
    due_at          DATETIME NULL,                 -- dari sla_days (hari kerja)
    completed_at    DATETIME NULL,
    rejection_reason TEXT NULL,
    revision_note   TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lr_ticket (ticket_number),
    KEY idx_lr_status    (status, submitted_at),
    KEY idx_lr_resident  (resident_id, created_at),
    KEY idx_lr_type      (letter_type_id, status),
    KEY idx_lr_due       (status, due_at),         -- pemantauan SLA
    KEY idx_lr_requester (requested_by, created_at),
    CONSTRAINT fk_lr_type     FOREIGN KEY (letter_type_id)
        REFERENCES letter_types(id) ON DELETE RESTRICT,
    CONSTRAINT fk_lr_resident FOREIGN KEY (resident_id)
        REFERENCES residents(id)    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Riwayat transisi status (jejak audit layanan)
CREATE TABLE letter_request_logs (
    id          CHAR(36)    NOT NULL PRIMARY KEY,
    request_id  CHAR(36)    NOT NULL,
    from_status VARCHAR(20) NULL,
    to_status   VARCHAR(20) NOT NULL,
    step        SMALLINT UNSIGNED NULL,
    actor_id    CHAR(36)     NULL,
    actor_name  VARCHAR(150) NULL,   -- disalin: tahan terhadap penghapusan akun
    note        TEXT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_lrl_request (request_id, created_at),
    CONSTRAINT fk_lrl_request FOREIGN KEY (request_id)
        REFERENCES letter_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```

```sql
-- Penghitung nomor surat (ADR-001) — titik serialisasi penomoran
CREATE TABLE letter_counters (
    letter_type_id CHAR(36) NOT NULL,       -- '-' bila pola nomor tunggal (dok.13 §3C)
    year           SMALLINT UNSIGNED NOT NULL,
    last_number    INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (letter_type_id, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```
**Prosedur penerbitan nomor (ADR-001 — tidak berubah di MySQL):**
```sql
START TRANSACTION;

-- Baris penghitung dibuat lebih dulu bila belum ada, lalu dikunci.
INSERT INTO letter_counters (letter_type_id, year, last_number)
VALUES (:type_id, :year, 0)
ON DUPLICATE KEY UPDATE letter_type_id = letter_type_id;   -- no-op bila sudah ada

SELECT last_number
  FROM letter_counters
 WHERE letter_type_id = :type_id AND year = :year
   FOR UPDATE;                       -- << serialisasi di sini (InnoDB row lock)

UPDATE letter_counters
   SET last_number = last_number + 1
 WHERE letter_type_id = :type_id AND year = :year;

INSERT INTO letters (...) VALUES (...);   -- nomor diformat dari last_number + 1

COMMIT;
```
> **Mengapa bukan `AUTO_INCREMENT`:** `AUTO_INCREMENT` tidak transaksional — bila
> transaksi *rollback*, nomor hilang dan deret berlubang. Audit administrasi desa
> tidak menerima nomor yang terlewat.
>
> **Mengapa `INSERT … ON DUPLICATE KEY` dulu:** `SELECT … FOR UPDATE` pada baris yang
> **belum ada** tidak mengunci apa pun di MySQL, sehingga dua transaksi pertama di awal
> tahun bisa sama-sama lolos. Menyisipkan baris penghitung lebih dulu menutup celah ini.
> **Ini kasus uji wajib** — lihat 09 §5 TC-SRT-08 varian (b).

```sql
-- Surat TERBIT: snapshot permanen & tidak diubah (ADR-003)
CREATE TABLE letters (
    id              CHAR(36)     NOT NULL PRIMARY KEY,
    request_id      CHAR(36)     NOT NULL,
    letter_type_id  CHAR(36)     NOT NULL,
    letter_number   VARCHAR(100) NOT NULL,
    sequence_number INT UNSIGNED NOT NULL,
    issued_at       DATETIME     NOT NULL,
    issued_year     SMALLINT UNSIGNED
        GENERATED ALWAYS AS (YEAR(issued_at)) STORED,
    issued_by       CHAR(36)     NOT NULL,         -- akun yang menyetujui
    signer_official_id CHAR(36)  NULL,
    signer_name     VARCHAR(150) NOT NULL,         -- disalin saat terbit
    signer_position VARCHAR(100) NOT NULL,
    valid_until     DATE NULL,                     -- dari validity_months
    -- Snapshot: data pemohon & isi surat SAAT TERBIT (prinsip §1.6)
    snapshot        JSON         NOT NULL,
    rendered_html   MEDIUMTEXT   NOT NULL,
    pdf_path        VARCHAR(255) NOT NULL,
    pdf_hash        CHAR(64)     NOT NULL,         -- SHA-256 berkas PDF
    verification_code VARCHAR(32) NOT NULL,        -- dipakai pada QR
    status          ENUM('sah','dibatalkan') NOT NULL DEFAULT 'sah',
    cancelled_at    DATETIME NULL,
    cancelled_by    CHAR(36) NULL,
    cancel_reason   TEXT NULL,
    replaces_letter_id CHAR(36) NULL,              -- RULE-05
    print_count     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_letters_number (letter_number),
    UNIQUE KEY uq_letters_verif  (verification_code),
    UNIQUE KEY uq_letters_seq    (letter_type_id, issued_year, sequence_number),
    KEY idx_letters_issued  (issued_at),
    KEY idx_letters_request (request_id),
    CONSTRAINT fk_letters_request FOREIGN KEY (request_id)
        REFERENCES letter_requests(id) ON DELETE RESTRICT,
    CONSTRAINT fk_letters_type FOREIGN KEY (letter_type_id)
        REFERENCES letter_types(id)    ON DELETE RESTRICT,
    CONSTRAINT fk_letters_replaces FOREIGN KEY (replaces_letter_id)
        REFERENCES letters(id)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```
> `uq_letters_seq (letter_type_id, issued_year, sequence_number)` adalah jaring
> pengaman terakhir: walau ada bug di kode penomoran, basis data menolak nomor ganda.
> `issued_year` dibuat sebagai kolom *generated* karena MySQL tidak mengizinkan
> ekspresi (`YEAR(issued_at)`) langsung di dalam indeks UNIQUE.

### 3.4 Identitas & Otorisasi

```sql
CREATE TABLE users (
    id                CHAR(36)     NOT NULL PRIMARY KEY,
    resident_id       CHAR(36)     NULL,           -- tautan akun warga ke data penduduk
    name              VARCHAR(150) NOT NULL,
    email             VARCHAR(150) NULL,
    phone             VARCHAR(30)  NULL,
    username          VARCHAR(60)  NULL,
    password_hash     VARCHAR(255) NOT NULL,
    email_verified_at DATETIME NULL,
    phone_verified_at DATETIME NULL,
    two_factor_secret VARBINARY(255) NULL,         -- terenkripsi
    two_factor_confirmed_at DATETIME NULL,
    two_factor_recovery_codes VARBINARY(1024) NULL,
    status ENUM('menunggu_verifikasi','aktif','nonaktif','terkunci')
           NOT NULL DEFAULT 'menunggu_verifikasi',
    verification_method ENUM('ktp_upload','tatap_muka') NULL,
    verified_by       CHAR(36) NULL,
    verified_at       DATETIME NULL,
    failed_login_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until      DATETIME NULL,
    last_login_at     DATETIME NULL,
    last_login_ip     VARCHAR(45) NULL,            -- cukup untuk IPv6
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    email_active VARCHAR(150)
        GENERATED ALWAYS AS (IF(deleted_at IS NULL, email, NULL)) STORED,
    phone_active VARCHAR(30)
        GENERATED ALWAYS AS (IF(deleted_at IS NULL, phone, NULL)) STORED,
    UNIQUE KEY uq_users_email (email_active),
    UNIQUE KEY uq_users_phone (phone_active),
    KEY idx_users_resident (resident_id),
    KEY idx_users_status   (status),
    CONSTRAINT fk_users_resident FOREIGN KEY (resident_id)
        REFERENCES residents(id) ON DELETE SET NULL,
    CONSTRAINT chk_users_contact CHECK (email IS NOT NULL OR phone IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE roles (
    id          CHAR(36)     NOT NULL PRIMARY KEY,
    code        VARCHAR(40)  NOT NULL,
    name        VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    is_system   TINYINT(1) NOT NULL DEFAULT 0,     -- tidak dapat dihapus
    UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE permissions (
    id     CHAR(36)     NOT NULL PRIMARY KEY,
    code   VARCHAR(60)  NOT NULL,                  -- 'penduduk.lihat'
    module VARCHAR(40)  NOT NULL,
    name   VARCHAR(120) NOT NULL,
    UNIQUE KEY uq_permissions_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE role_permissions (
    role_id       CHAR(36) NOT NULL,
    permission_id CHAR(36) NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_rp_role FOREIGN KEY (role_id)
        REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id)
        REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE user_roles (
    user_id   CHAR(36) NOT NULL,
    role_id   CHAR(36) NOT NULL,
    region_id CHAR(36) NULL,          -- pembatasan wilayah (Kadus/RT) — lihat catatan
    PRIMARY KEY (user_id, role_id),
    KEY idx_ur_region (region_id),
    CONSTRAINT fk_ur_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ur_role FOREIGN KEY (role_id)
        REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_ur_region FOREIGN KEY (region_id)
        REFERENCES regions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```
> **⚠ Catatan paling penting pada skema ini (konsekuensi KEP-002):**
> `user_roles.region_id` adalah **satu-satunya** sumber pembatasan wilayah untuk
> Kepala Dusun, dan `users.resident_id` + `residents.family_id` satu-satunya sumber
> pembatasan data-sendiri untuk Warga. Tanpa Row-Level Security, **tidak ada jaring
> pengaman di tingkat basis data** bila kode lupa menerapkan filter ini.
>
> Karena itu: setiap kueri ke `residents`, `families`, `letter_requests`, dan
> `complaints` **wajib** melalui satu lapis *repository* yang menerapkan pembatasan
> tersebut (04 §3.3, KEP-001). Lapis uji otorisasi 09 §4.3 bersifat **wajib**, dan
> `tests/Authorization/` tidak boleh di-*skip* di CI dengan alasan apa pun.

### 3.5 Pengaduan

```sql
CREATE TABLE complaints (
    id             CHAR(36)    NOT NULL PRIMARY KEY,
    ticket_number  VARCHAR(40) NOT NULL,
    tracking_code  VARCHAR(20) NOT NULL,           -- untuk pelapor anonim
    category_code  VARCHAR(40) NOT NULL,
    title          VARCHAR(200) NOT NULL,
    body           TEXT NOT NULL,
    is_anonymous   TINYINT(1) NOT NULL DEFAULT 0,
    reporter_user_id CHAR(36) NULL,                -- NULL bila anonim (RULE-09)
    reporter_name  VARCHAR(150) NULL,
    reporter_phone VARCHAR(30)  NULL,
    region_id      CHAR(36) NULL,
    location_text  VARCHAR(255) NULL,
    latitude       DECIMAL(10,7) NULL,
    longitude      DECIMAL(10,7) NULL,
    status   ENUM('baru','diterima','diproses','selesai','ditolak')
             NOT NULL DEFAULT 'baru',
    priority ENUM('rendah','normal','tinggi') NOT NULL DEFAULT 'normal',
    assigned_to   CHAR(36) NULL,
    assigned_at   DATETIME NULL,
    due_at        DATETIME NULL,
    resolved_at   DATETIME NULL,
    resolution    TEXT NULL,
    is_published  TINYINT(1) NOT NULL DEFAULT 0,
    submitted_ip  VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_complaints_ticket   (ticket_number),
    UNIQUE KEY uq_complaints_tracking (tracking_code),
    KEY idx_complaints_status   (status, created_at),
    KEY idx_complaints_assigned (assigned_to, status),
    KEY idx_complaints_due      (status, due_at),
    KEY idx_complaints_reporter (reporter_user_id),
    CONSTRAINT chk_complaints_anon CHECK (
        is_anonymous = 0
        OR (reporter_user_id IS NULL AND reporter_name IS NULL AND reporter_phone IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE complaint_actions (
    id           CHAR(36) NOT NULL PRIMARY KEY,
    complaint_id CHAR(36) NOT NULL,
    actor_id     CHAR(36) NULL,
    actor_name   VARCHAR(150) NULL,
    from_status  VARCHAR(20) NULL,
    to_status    VARCHAR(20) NULL,
    note         TEXT NOT NULL,
    is_visible_to_reporter TINYINT(1) NOT NULL DEFAULT 1,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ca_complaint (complaint_id, created_at),
    CONSTRAINT fk_ca_complaint FOREIGN KEY (complaint_id)
        REFERENCES complaints(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```
> `chk_complaints_anon` menegakkan RULE-09 di tingkat basis data: pengaduan anonim
> **tidak dapat** menyimpan identitas pelapor, bahkan bila kode aplikasi keliru.

### 3.6 Portal, Berkas, Audit, Pengaturan

```sql
CREATE TABLE posts (                    -- berita, pengumuman, agenda
    id            CHAR(36) NOT NULL PRIMARY KEY,
    type          ENUM('berita','pengumuman','agenda') NOT NULL,
    slug          VARCHAR(200) NOT NULL,
    title         VARCHAR(250) NOT NULL,
    excerpt       VARCHAR(500) NULL,
    body          MEDIUMTEXT NOT NULL,
    cover_path    VARCHAR(255) NULL,
    category_code VARCHAR(40) NULL,
    tags          JSON NULL,
    event_start_at DATETIME NULL,       -- khusus agenda
    event_end_at   DATETIME NULL,
    event_location VARCHAR(255) NULL,
    status        ENUM('draft','terbit','arsip') NOT NULL DEFAULT 'draft',
    published_at  DATETIME NULL,
    author_id     CHAR(36) NULL,
    view_count    INT UNSIGNED NOT NULL DEFAULT 0,
    meta_title       VARCHAR(200) NULL,
    meta_description VARCHAR(300) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    slug_active VARCHAR(200)
        GENERATED ALWAYS AS (IF(deleted_at IS NULL, slug, NULL)) STORED,
    UNIQUE KEY uq_posts_slug (slug_active),
    KEY idx_posts_public (type, status, published_at),
    FULLTEXT KEY ft_posts (title, body)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE attachments (
    id              CHAR(36) NOT NULL PRIMARY KEY,
    attachable_type VARCHAR(60) NOT NULL,          -- polimorfik
    attachable_id   CHAR(36)    NOT NULL,
    label           VARCHAR(150) NULL,
    original_name   VARCHAR(255) NOT NULL,
    storage_path    VARCHAR(500) NOT NULL,         -- kunci objek S3, bukan URL publik
    mime_type       VARCHAR(100) NOT NULL,
    size_bytes      BIGINT UNSIGNED NOT NULL,
    file_hash       CHAR(64) NOT NULL,
    scan_status     ENUM('menunggu','bersih','terinfeksi') NOT NULL DEFAULT 'menunggu',
    is_public       TINYINT(1) NOT NULL DEFAULT 0,
    uploaded_by     CHAR(36) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    KEY idx_attachments_owner (attachable_type, attachable_id),
    KEY idx_attachments_scan  (scan_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```

```sql
-- ADR-005: append-only, ber-hash-chain
CREATE TABLE audit_logs (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    actor_id       CHAR(36) NULL,
    actor_name     VARCHAR(150) NULL,
    actor_roles    VARCHAR(255) NULL,              -- "admin_desa,kasi_pemerintahan"
    action         VARCHAR(60) NOT NULL,           -- resident.updated, letter.issued
    entity_type    VARCHAR(60) NULL,
    entity_id      CHAR(36) NULL,
    changes        JSON NULL,                      -- nilai lama/baru (ter-masking)
    context        JSON NULL,                      -- {"rows":1500,"filter":{...}}
    ip_address     VARCHAR(45) NULL,
    user_agent     VARCHAR(300) NULL,
    correlation_id CHAR(36) NULL,
    prev_hash      CHAR(64) NULL,
    row_hash       CHAR(64) NOT NULL,
    hash_version   TINYINT UNSIGNED NOT NULL DEFAULT 1,  -- rumus hash, KEP-020
    created_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY idx_audit_entity  (entity_type, entity_id, created_at),
    KEY idx_audit_actor   (actor_id, created_at),
    KEY idx_audit_action  (action, created_at),
    KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```
**Penegakan *append-only* (wajib dijalankan saat pemasangan):**
```sql
-- Pengguna aplikasi HANYA boleh menyisipkan dan membaca audit log.
REVOKE UPDATE, DELETE ON sid_desa.audit_logs FROM 'sid_app'@'%';
GRANT  INSERT, SELECT  ON sid_desa.audit_logs TO   'sid_app'@'%';
FLUSH PRIVILEGES;
```
> **Tiga keputusan di tabel ini, beserta alasannya:**
> 1. **`BIGINT AUTO_INCREMENT`, bukan UUID.** Audit log tidak pernah dirujuk dari luar
>    dan jumlahnya paling banyak. PK berurutan membuat penulisan InnoDB jauh lebih
>    efisien dan menghemat ruang (8 byte vs 36).
> 2. **Tanpa partisi.** Pada satu desa, pertumbuhannya ± 20.000 baris/bulan
>    (± 1,2 juta dalam 5 tahun) — masih nyaman tanpa partisi. Bila kelak diperlukan,
>    partisi RANGE menuntut `created_at` masuk ke seluruh kunci unik; itu perubahan
>    skema yang mudah dilakukan nanti. **Yang dipasang sekarang:** *job* pengarsipan
>    bulanan yang memindahkan baris > 5 tahun ke `audit_logs_archive`.
> 3. **Tanpa foreign key ke `users`.** Audit log harus tetap utuh walau akun pengguna
>    dihapus — karena itu `actor_name` disalin, bukan di-*join*.
> 4. **`hash_version` ada karena rumus hash pernah salah (KEP-020).** Rumus v1
>    tidak pernah memasukkan kolom `changes` ke dalam rantai — nama `$this->changes`
>    di dalam model mengenai properti `HasAttributes`, bukan kolomnya — sehingga
>    nilai lama/baru data penduduk dapat diubah di luar aplikasi tanpa merusak
>    rantai. Ia juga meng-*encode* kolom JSON menurut urutan kunci saat
>    penulisan, padahal MySQL menyimpan JSON dalam bentuk binernya sendiri dan
>    mengembalikan kuncinya **terurut** — sehingga verifikasi melaporkan rantai
>    RUSAK atas baris yang jujur. Rumus v2 memperbaiki keduanya.
>
>    **Baris v1 tidak dapat dihitung ulang**, dan itu bukan kelonggaran
>    melainkan satu-satunya pernyataan yang jujur: urutan kunci JSON aslinya
>    sudah hilang saat MySQL menyimpannya. `sid:verify-audit-chain` karena itu
>    memeriksa **sambungannya saja** pada baris v1 (`prev_hash` baris berikutnya
>    sama dengan `row_hash` baris ini) dan melaporkan jumlahnya terpisah sebagai
>    peringatan. Perubahan medan pada baris v1 memang tidak terdeteksi — sama
>    seperti sebelum KEP-020 — dan itu dapat diterima HANYA karena sistem belum
>    pernah berjalan di desa saat rumusnya diperbaiki.
>
>    **Bawaannya 1, bukan 2**, supaya baris lama menjadi v1 tanpa satu pun
>    `UPDATE` — yang memang mustahil, karena hak akses MySQL di bawah ini
>    mencabut UPDATE atas tabel ini — dan supaya penyisip massal yang tidak
>    melewati `AuditLogger` melaporkan dirinya v1 secara jujur alih-alih
>    mengaku v2.

```sql
-- Mutex rantai audit — KEP-020. SATU baris, tanpa kolom lain.
CREATE TABLE audit_chain_lock (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO audit_chain_lock (id) VALUES (1) ON DUPLICATE KEY UPDATE id = id;
```
> **Tabel tanpa data, dan itu memang gunanya.** Perangkaian audit menuntut agar
> tidak ada dua permintaan yang membaca ujung rantai yang sama — tanpa itu
> keduanya memakai `prev_hash` yang sama dan rantainya bercabang. Penguncian
> `ORDER BY id DESC LIMIT 1 FOR UPDATE` atas `audit_logs` melakukan itu, tetapi
> ia mengambil *next-key lock* di ujung indeks; **gap lock InnoDB saling
> kompatibel**, sehingga dua penyisipan audit bersamaan dapat saling menunggu
> lewat *insert-intention lock* dan MySQL membunuh salah satunya (galat 1213).
> Terukur pada uji beban Sprint 7: sekali per ± 44 menit, muncul sebagai HTTP
> 500 di depan petugas.
>
> Baris ini dikunci **sebelum** ujung rantai dibaca. Karena penguncian dengan
> kesetaraan kunci primer tidak mengambil gap lock, ia mengubah *deadlock*
> menjadi antrean tunggu biasa: terukur 5 dari 6 gagal tanpanya, 6 dari 6
> berhasil dengannya.
>
> Ia **sengaja tidak menyimpan hash ujung rantai**. Nilai itu akan menjadi
> sumber kebenaran kedua yang basi setiap kali sesuatu menyisipkan baris tanpa
> melewati `AuditLogger` — yang memang dilakukan penyemai volume. Ujung rantai
> tetap dibaca dari `audit_logs`.
>
> Tabel ini **tidak** ikut dalam `REVOKE` di atas: aplikasi harus dapat
> mengunci barisnya, dan tidak ada bukti apa pun yang tersimpan di dalamnya.

```sql
CREATE TABLE settings (
    `key`      VARCHAR(80) NOT NULL PRIMARY KEY,
    value      JSON NOT NULL,
    updated_by CHAR(36) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```
> `key` adalah kata kunci MySQL — **wajib** ditulis dalam *backtick*. Bila ingin
> menghindarinya, ubah nama kolom menjadi `setting_key`.

### 3.7 Tabel Referensi (`ref_*`)

Pola seragam untuk 12 tabel referensi:
```sql
CREATE TABLE ref_religions (
    code       VARCHAR(20)  NOT NULL PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    `group`    VARCHAR(40)  NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    KEY idx_ref_religions_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```
Nilai awal diisi lewat *seeder* mengikuti kode Dukcapil/BPS:

| Tabel | Isi contoh |
|-------|-----------|
| `ref_religions` | Islam, Kristen, Katolik, Hindu, Buddha, Konghucu, Kepercayaan |
| `ref_marital_statuses` | Belum Kawin, Kawin Tercatat, Kawin Belum Tercatat, Cerai Hidup, Cerai Mati |
| `ref_family_relations` | Kepala Keluarga, Suami, Istri, Anak, Menantu, Cucu, Orang Tua, Famili Lain, Lainnya |
| `ref_educations` | Tidak/Belum Sekolah … S3 (10 tingkat Dukcapil) |
| `ref_occupations` | Daftar pekerjaan Dukcapil (± 90 entri) |
| `ref_blood_types` | A, B, AB, O, A+, A−, …, Tidak Tahu |
| `ref_disabilities` | Fisik, Netra, Rungu/Wicara, Mental, Fisik & Mental, Lainnya |
| `ref_welfare_statuses` | Sangat Miskin, Miskin, Rentan Miskin, Menengah, Sejahtera |
| `ref_house_ownerships` | Milik Sendiri, Sewa/Kontrak, Menumpang, Rumah Dinas, Lainnya |
| `ref_complaint_categories` | Infrastruktur, Pelayanan, Bantuan Sosial, Lingkungan, Keamanan, Lainnya |
| `ref_post_categories` | Pembangunan, Kesehatan, Pendidikan, Ekonomi, Sosial |
| `ref_asset_categories` | Tanah, Bangunan, Peralatan & Mesin, Jalan/Jaringan, Aset Lainnya |

> **Mengapa `code` (VARCHAR) sebagai PK, bukan `id` numerik:** nilai referensi muncul
> di ekspor Excel, laporan, dan API. Kode yang terbaca manusia (`islam`, `kepala_keluarga`)
> membuat data dapat dipahami tanpa *join*, dan mencegah kesalahan pemetaan saat impor.

---

## 4. Statistik: Tabel Ringkasan (pengganti *materialized view*)

MySQL tidak punya `MATERIALIZED VIEW`. Penggantinya adalah tabel ringkasan yang
disegarkan oleh *job* aplikasi.

```sql
CREATE TABLE population_stats (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    region_id      CHAR(36) NULL,                  -- NULL = seluruh desa
    gender         ENUM('L','P') NULL,
    age_group      VARCHAR(10) NULL,               -- '0-4','5-14','15-24','25-44','45-64','65+'
    religion_code       VARCHAR(20) NULL,
    education_code      VARCHAR(20) NULL,
    occupation_code     VARCHAR(30) NULL,
    marital_status_code VARCHAR(20) NULL,
    total          INT UNSIGNED NOT NULL,
    computed_at    DATETIME NOT NULL,
    UNIQUE KEY uq_pop_stats (region_id, gender, age_group, religion_code,
                             education_code, occupation_code, marital_status_code),
    KEY idx_pop_stats_region (region_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```

**Kueri penyegaran** (dijalankan *job*, bukan MySQL EVENT — agar ikut *version control*
dan dapat diuji):
```sql
INSERT INTO population_stats
    (region_id, gender, age_group, religion_code, education_code,
     occupation_code, marital_status_code, total, computed_at)
SELECT
    region_id, gender,
    CASE
        WHEN age_years < 5  THEN '0-4'    WHEN age_years < 15 THEN '5-14'
        WHEN age_years < 25 THEN '15-24'  WHEN age_years < 45 THEN '25-44'
        WHEN age_years < 65 THEN '45-64'  ELSE '65+'
    END AS age_group,
    religion_code, education_code, occupation_code, marital_status_code,
    COUNT(*), NOW()
FROM (
    SELECT region_id, gender, religion_code, education_code, occupation_code,
           marital_status_code,
           TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) AS age_years
    FROM residents
    WHERE deleted_at IS NULL AND status = 'aktif'      -- RULE-03
) r
GROUP BY region_id, gender, age_group, religion_code,
         education_code, occupation_code, marital_status_code
ON DUPLICATE KEY UPDATE total = VALUES(total), computed_at = VALUES(computed_at);
```

**Kapan disegarkan:**
| Pemicu | Catatan |
|--------|---------|
| Terjadwal tiap 1 jam | Penyegaran rutin |
| Setelah impor massal selesai | Langsung, agar angka tidak membingungkan petugas |
| Setelah mutasi penduduk | Dengan *debounce* 1 menit agar tidak terpicu berkali-kali |
| Manual dari UI | Tombol "Segarkan statistik" untuk Admin Desa |

**Konsekuensi yang diterima:** angka statistik dapat tertinggal sampai 1 jam.
Portal publik menampilkan "Data per: {{computed_at}}" agar tidak menyesatkan. Baris
dengan `total` 1–4 disamarkan menjadi "< 5" **saat disajikan** (ADR-008) — penyamaran
dilakukan di lapis API, bukan di tabel, agar laporan internal tetap akurat.

**View kinerja layanan** (view biasa; MySQL mendukung ini):
```sql
CREATE OR REPLACE VIEW v_service_performance AS
SELECT lr.letter_type_id,
       lt.name AS letter_type_name,
       DATE_FORMAT(lr.submitted_at, '%Y-%m-01') AS period,
       COUNT(*)                                                      AS total_requests,
       SUM(lr.status = 'diterbitkan')                                AS total_issued,
       SUM(lr.status = 'ditolak')                                    AS total_rejected,
       SUM(lr.completed_at IS NOT NULL AND lr.completed_at <= lr.due_at) AS on_time,
       ROUND(AVG(TIMESTAMPDIFF(MINUTE, lr.submitted_at, lr.completed_at)) / 60, 2)
            AS avg_hours
FROM letter_requests lr
JOIN letter_types lt ON lt.id = lr.letter_type_id
WHERE lr.submitted_at IS NOT NULL
GROUP BY lr.letter_type_id, lt.name, period;
```

---

## 5. Tabel Fase 2 (Ringkas)

```sql
-- Keuangan: publikasi & rekap saja, BUKAN pembukuan (lihat 01 §3.4)
budgets (id, fiscal_year, revision_no, status ENUM('draft','final','publikasi'),
         total_revenue, total_expenditure, total_financing,
         approved_by, published_at, source_document_id,
         UNIQUE (fiscal_year, revision_no))
budget_items (id, budget_id, parent_id, account_code, field_code, activity_name,
              planned_amount DECIMAL(18,2), unit, sort_order)
budget_realizations (id, budget_item_id, period, realized_amount DECIMAL(18,2),
                     note, recorded_at)

-- Aset desa
assets (id, code, name, category_code, acquisition_date, acquisition_source,
        acquisition_value DECIMAL(18,2), current_value DECIMAL(18,2),
        quantity, unit, condition ENUM('baik','rusak_ringan','rusak_berat'),
        location, region_id, ownership_doc_no, photo_path, notes,
        UNIQUE (code))
asset_movements (id, asset_id,
                 type ENUM('penambahan','pengurangan','pemindahan','penghapusan'),
                 movement_date, quantity, value DECIMAL(18,2), note, document_no)

-- Bantuan sosial
aid_programs (id, name, source ENUM('APBN','APBD','APBDes','CSR','Lainnya'),
              period, benefit_type, benefit_amount DECIMAL(18,2), quota,
              criteria JSON, status)
aid_recipients (id, program_id, resident_id, family_id, proposed_by,
                verification_status ENUM('diusulkan','terverifikasi','ditolak'),
                verified_by, verified_at, received_at, note,
                UNIQUE KEY uq_aid_recipient (program_id, resident_id))
```
> `uq_aid_recipient (program_id, resident_id)` mencegah satu warga terdaftar dua kali
> dalam satu program. Deteksi penerima ganda **antar** program dilakukan lewat laporan
> (FR-BSS-05), bukan *constraint* — karena menerima dua program berbeda kadang sah.

---

## 6. Pengaturan Server MySQL

```ini
# /etc/mysql/conf.d/sid.cnf
[mysqld]
sql_mode = STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,ONLY_FULL_GROUP_BY,NO_ENGINE_SUBSTITUTION
character_set_server = utf8mb4
collation_server     = utf8mb4_0900_ai_ci
default_storage_engine = InnoDB
default_time_zone    = '+00:00'          # simpan UTC; konversi di aplikasi

innodb_buffer_pool_size = 2G             # ± 50-70% RAM server basis data
innodb_flush_log_at_trx_commit = 1       # aman terhadap mati listrik (jangan 2)
innodb_file_per_table   = ON
innodb_log_file_size    = 512M
max_connections         = 150
transaction_isolation   = READ-COMMITTED # lebih sedikit gap lock daripada REPEATABLE-READ

# Wajib untuk pemulihan titik-waktu (RPO ≤ 1 jam, NFR-REL-02)
log_bin                 = /var/log/mysql/mysql-bin
binlog_format           = ROW
binlog_expire_logs_seconds = 604800      # 7 hari
sync_binlog             = 1

slow_query_log          = ON
long_query_time         = 1
log_queries_not_using_indexes = ON       # aktifkan di staging; matikan di produksi
```
| Pengaturan | Mengapa penting |
|-----------|-----------------|
| `sql_mode` ketat | Tanpa ini MySQL **memotong** data terlalu panjang dan menerima tanggal `0000-00-00` secara diam-diam — sumber kerusakan data yang sulit dilacak |
| `innodb_flush_log_at_trx_commit = 1` | Transaksi yang sudah *commit* tidak hilang saat listrik mati. Kantor desa sering mengalami pemadaman |
| `log_bin` + `sync_binlog = 1` | Tanpa binlog, RPO hanya sebaik cadangan harian (≤ 24 jam, bukan ≤ 1 jam) |
| `READ-COMMITTED` | Mengurangi *gap lock*; aman karena penomoran surat memakai *locking* eksplisit (§3.3) |

---

## 7. Strategi Migrasi Data Awal

**Langkah (tidak berubah dari v1.0):**
1. Kumpulkan sumber: buku induk, Excel per bidang, ekspor Prodeskel/DTKS, OpenSID bila ada.
2. Pemetaan kolom ke template resmi (satu lembar pemetaan per sumber).
3. *Profiling* kualitas: NIK tidak valid, tanggal lahir kosong, duplikat nama.
4. Pembersihan di *staging*, **bukan** di produksi.
5. *Dry-run* impor → laporan galat → perbaikan → ulang sampai tingkat galat < 2%.
6. Impor produksi + **verifikasi sampel 5%** oleh perangkat desa.
7. Verifikasi lapangan oleh RT/Kadus untuk baris bertanda anomali.
8. Jalankan penyegaran `population_stats`, lalu bandingkan total dengan data manual desa.

**Catatan khusus MySQL untuk impor massal:**
```sql
SET FOREIGN_KEY_CHECKS = 0;    -- hanya dalam transaksi impor, WAJIB dinyalakan kembali
SET UNIQUE_CHECKS = 0;
SET autocommit = 0;
-- ... batch INSERT 1.000 baris per pernyataan ...
COMMIT;
SET UNIQUE_CHECKS = 1;
SET FOREIGN_KEY_CHECKS = 1;
-- Verifikasi integritas SETELAH impor: cek NIK duplikat, KK tanpa kepala keluarga
```
> ⚠ Mematikan `UNIQUE_CHECKS` mempercepat impor tetapi **menunda** deteksi duplikat.
> Karena itu validasi duplikat **wajib** dilakukan di lapis aplikasi pada tahap
> pra-validasi (langkah 5), bukan diserahkan ke basis data.

**Retensi (RULE-12)** — tidak berubah:
| Data | Retensi aktif | Setelah itu |
|------|---------------|-------------|
| Penduduk aktif | Permanen | — |
| Penduduk meninggal/pindah | 10 tahun | Arsip (dapat dibaca, tidak masuk statistik) |
| Pengajuan surat | 5 tahun | Arsip dingin |
| Surat terbit (PDF) | **Permanen** | — (dokumen bernilai hukum) |
| Pengaduan selesai | 3 tahun | Anonimkan identitas pelapor |
| Audit log | 5 tahun | `audit_logs_archive` (*immutable*) |
| Berkas pengajuan ditolak | 1 tahun | Hapus permanen |
| Sesi & token | 30 hari | Hapus |

---

## 8. Perkiraan Ukuran (satu desa, 5.000 jiwa)

| Tabel | Baris (tahun 1) | Baris (tahun 5) | Ukuran tahun 5 |
|-------|-----------------|-----------------|----------------|
| `residents` | 5.000 | 5.800 | ± 8 MB |
| `families` | 1.500 | 1.700 | ± 2 MB |
| `letter_requests` | 12.000 | 60.000 | ± 30 MB |
| `letters` (termasuk `rendered_html`) | 11.000 | 55.000 | ± 300 MB |
| `letter_request_logs` | 40.000 | 200.000 | ± 25 MB |
| `complaints` + `complaint_actions` | 500 | 2.500 | ± 5 MB |
| `audit_logs` | 240.000 | 1.200.000 | ± 500 MB |
| `population_stats` | ± 3.000 | ± 3.000 | < 1 MB |
| **Total basis data** | | | **± 900 MB** |
| Berkas di object storage (lampiran + PDF) | ± 4 GB | ± 20 GB | — |

> Kesimpulan: untuk satu desa, basis data tetap **di bawah 1 GB setelah 5 tahun**.
> Seluruhnya masuk ke `innodb_buffer_pool` 2 GB, sehingga hampir semua kueri dilayani
> dari memori. Ini yang membuat banyak kekhawatiran kinerja pada dokumen 03 §5.1
> praktis tidak berlaku di skala ini — dan mengapa pencarian `LIKE '%...%'` (§3.2)
> dapat diterima.
>
> Yang **tetap** perlu diperhatikan: `rendered_html` di tabel `letters` adalah
> penyumbang ukuran terbesar. Bila kelak menjadi masalah, pindahkan ke object storage
> dan simpan hanya jalurnya — PDF-nya sudah ada di sana.

---

## 9. Perbedaan dari PostgreSQL (Rujukan)

Ringkasan untuk pembaca yang mengenal versi v1.0 dokumen ini:

| Kebutuhan | PostgreSQL (v1.0) | MySQL 8 (v2.0 — yang dipakai) |
|-----------|-------------------|-------------------------------|
| Isolasi antar-desa | Row-Level Security | **Tidak diperlukan** (satu desa, KEP-001) |
| NIK unik hanya untuk aktif | `UNIQUE INDEX … WHERE deleted_at IS NULL` | Kolom *generated* `nik_hash_active` + UNIQUE (§2.2) |
| Satu kepala keluarga per KK | *partial unique index* | Kolom *generated* `family_head_key` + UNIQUE (§2.2) |
| Nomor surat tanpa celah | `SELECT … FOR UPDATE` | **Sama**, + `INSERT … ON DUPLICATE KEY` lebih dulu (§3.3) |
| Keunikan nomor urut per tahun | Ekspresi `EXTRACT(YEAR …)` di dalam UNIQUE | Kolom *generated* `issued_year` (§3.3) |
| Formulir dinamis | `JSONB` + indeks GIN | `JSON` + kolom *generated* bila perlu diindeks |
| Statistik agregat | `MATERIALIZED VIEW` + `REFRESH CONCURRENTLY` | Tabel `population_stats` + *job* penyegar (§4) |
| Pencarian toleran salah ketik | `pg_trgm` | **Tidak ada** — `full_name_norm` + `LIKE` (§3.2) ⚠ |
| Tipe IP | `INET` | `VARCHAR(45)` |
| Larik | `TEXT[]` | `JSON` |
| Audit log tak dapat diubah | `REVOKE` + partisi RANGE | `REVOKE` (sama) + pengarsipan terjadwal (§3.6) |
| Enkripsi kolom NIK | Lapis aplikasi | **Sama** (lapis aplikasi, bukan fitur DBMS) |
| Timestamp berzona | `TIMESTAMPTZ` | `DATETIME` + server UTC + konversi aplikasi |

**Satu-satunya kemampuan yang benar-benar hilang** adalah pencarian nama toleran
salah ketik. Semua lainnya punya padanan yang setara secara fungsional.

---

## 10. Rencana Retrofit Multi-Desa (bila kelak diperlukan)

Dicatat sekarang agar biayanya diketahui, bukan menjadi kejutan (KEP-001).

| Langkah | Pekerjaan | Perkiraan |
|---------|-----------|-----------|
| 1 | `village_profile` → `tenants` (hapus `CHECK (id=1)`, PK jadi CHAR(36)) | 1 hari |
| 2 | `ALTER TABLE … ADD COLUMN tenant_id CHAR(36) NOT NULL DEFAULT '<uuid-desa>'` pada ± 18 tabel | 1 hari |
| 3 | Tambahkan `tenant_id` sebagai kolom **pertama** pada seluruh indeks UNIQUE dan indeks pencarian | 2 hari |
| 4 | Sertakan `tenant_id` ke dalam semua kolom *generated* keunikan bersyarat (§2.2) | 1 hari |
| 5 | Tambahkan *global scope* tenant di lapis *repository* (satu tempat — syarat KEP-001) | 2 hari |
| 6 | Middleware penentu tenant dari subdomain + tabel `tenant_domains` | 2 hari |
| 7 | **Uji ulang seluruh otorisasi** dengan skenario lintas tenant (09 §4.3) | 5–8 hari |
| 8 | Migrasi data & verifikasi | 2 hari |
| **Total** | | **16–19 hari-orang** |

**Langkah 7 adalah yang paling mahal dan paling tidak boleh dipangkas** — di situlah
seluruh risiko kebocoran lintas desa berada.

**Alternatif yang sering lebih baik untuk < 10 desa:** satu instalasi (basis data +
kontainer) **per desa**. Biayanya linier tetapi rendah (± Rp 250–400 rb/bln per desa,
lihat 10 §11), isolasinya mutlak, dan **tanpa perubahan kode sama sekali**.
Pertimbangkan retrofit multi-tenancy hanya bila jumlah desa diperkirakan melewati ± 10.
