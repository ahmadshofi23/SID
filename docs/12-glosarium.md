# 12 — Glosarium

## Istilah Pemerintahan Desa

| Istilah | Penjelasan |
|---------|-----------|
| **APBDes** | Anggaran Pendapatan dan Belanja Desa — rencana keuangan tahunan desa |
| **BPD** | Badan Permusyawaratan Desa — lembaga pengawas & penyalur aspirasi warga |
| **BUMDes** | Badan Usaha Milik Desa |
| **Dukcapil** | Dinas Kependudukan dan Pencatatan Sipil — pemilik kewenangan data kependudukan |
| **DPMD** | Dinas Pemberdayaan Masyarakat dan Desa (tingkat kabupaten) |
| **DTKS** | Data Terpadu Kesejahteraan Sosial — basis data penerima bantuan sosial |
| **Kadus** | Kepala Dusun |
| **Kaur** | Kepala Urusan (Tata Usaha & Umum, Keuangan, Perencanaan) — unsur sekretariat |
| **Kasi** | Kepala Seksi (Pemerintahan, Kesejahteraan, Pelayanan) — unsur pelaksana teknis |
| **KK** | Kartu Keluarga / nomor Kartu Keluarga |
| **Musdes** | Musyawarah Desa |
| **NIK** | Nomor Induk Kependudukan — 16 digit, identitas tunggal penduduk |
| **Perdes** | Peraturan Desa |
| **Prodeskel** | Profil Desa dan Kelurahan — sistem pelaporan profil desa Kemendagri |
| **RKPDes** | Rencana Kerja Pemerintah Desa — rencana tahunan |
| **RPJMDes** | Rencana Pembangunan Jangka Menengah Desa — rencana 6 tahun |
| **Sekdes** | Sekretaris Desa |
| **SIAK** | Sistem Informasi Administrasi Kependudukan (Dukcapil) |
| **SID** | Sistem Informasi Desa |
| **SIPD** | Sistem Informasi Pemerintahan Daerah |
| **SISKEUDES** | Aplikasi Sistem Keuangan Desa — **sumber resmi** pembukuan keuangan desa |
| **SKTM** | Surat Keterangan Tidak Mampu |
| **SKD** | Surat Keterangan Domisili |
| **SKU** | Surat Keterangan Usaha |
| **Supra-desa** | Pemerintahan di atas desa (kecamatan, kabupaten) |
| **TTE** | Tanda Tangan Elektronik (tersertifikasi, mis. dari BSrE) |
| **Walk-in** | Warga datang langsung ke kantor desa (bukan pengajuan daring) |

## Istilah Teknis

| Istilah | Penjelasan |
|---------|-----------|
| **ADR** | *Architecture Decision Record* — catatan keputusan arsitektur beserta alasannya |
| **Append-only** | Data hanya dapat ditambahkan, tidak dapat diubah atau dihapus |
| **Audit log** | Catatan permanen setiap tindakan penting: siapa, apa, kapan |
| **BA** | *Business Analyst* |
| **BRD** | *Business Requirements Document* — dokumen kebutuhan bisnis |
| **CSP** | *Content Security Policy* — header pencegah penyisipan skrip berbahaya |
| **CI/CD** | *Continuous Integration / Continuous Deployment* — otomasi uji & rilis |
| **CR** | *Change Request* — permintaan perubahan lingkup |
| **DAST / SAST** | Pengujian keamanan dinamis (aplikasi berjalan) / statis (kode) |
| **DoD** | *Definition of Done* — syarat sebuah pekerjaan dianggap selesai |
| **DPA** | *Data Processing Agreement* — perjanjian pemrosesan data pribadi |
| **E2E** | *End-to-end* — uji alur lengkap seperti pengguna nyata |
| **ERD** | *Entity Relationship Diagram* — diagram relasi antar-tabel |
| **IDOR** | *Insecure Direct Object Reference* — celah akses data orang lain hanya dengan mengubah ID di URL |
| **Idempoten** | Permintaan yang sama berulang kali menghasilkan efek yang sama (tidak ganda) |
| **InnoDB** | Mesin penyimpanan MySQL yang mendukung transaksi & *foreign key* — satu-satunya yang dipakai |
| **Generated column** | Kolom MySQL yang nilainya dihitung dari kolom lain; dipakai untuk keunikan bersyarat (05 §2.2) |
| **Binlog** | Log biner MySQL berisi semua perubahan data; dasar pemulihan titik-waktu (RPO ≤ 1 jam) |
| **JSON (MySQL)** | Tipe kolom untuk formulir dinamis; MySQL **tidak** memvalidasi isinya terhadap skema — validasi di aplikasi |
| **LCP** | *Largest Contentful Paint* — waktu sampai isi utama halaman tampil |
| **Maker-checker** | Pembuat data berbeda orang dari pemeriksa/penyetuju |
| **Masking** | Penyamaran sebagian data, mis. `3201••••••••1234` |
| **Materialized view** | Hasil kueri yang disimpan fisik; cepat dibaca, perlu disegarkan. Tidak ada di MySQL → diganti tabel ringkasan + *job* (05 §4) |
| **MoSCoW** | Prioritas: *Must · Should · Could · Won't* |
| **Multi-tenant** | Satu instalasi melayani banyak organisasi (desa) dengan data terpisah. **Tidak dipakai di proyek ini** (KEP-001) |
| **Single-tenant** | Satu instalasi = satu organisasi (satu desa). Isolasi mutlak karena data pihak lain tidak ada di basis data |
| **NFR** | *Non-Functional Requirement* — kebutuhan mutu (kinerja, keamanan, dsb.) |
| **OpenAPI** | Standar penulisan spesifikasi API |
| **OWASP Top 10** | Daftar sepuluh risiko keamanan aplikasi web paling kritis |
| **PDP** | Pelindungan Data Pribadi (UU No. 27/2022) |
| **PII** | *Personally Identifiable Information* — data pribadi yang dapat mengidentifikasi orang |
| **PO** | *Product Owner* — penentu prioritas dari sisi pengguna |
| **PWA** | *Progressive Web App* — aplikasi web yang dapat dipasang di layar utama ponsel |
| **RBAC** | *Role-Based Access Control* — hak akses berdasarkan peran |
| **RLS** | *Row-Level Security* — pembatasan akses per baris di tingkat basis data. **Tidak tersedia di MySQL**; penggantinya lapis *repository* di aplikasi (04 §4.1) |
| **RPO / RTO** | *Recovery Point Objective* (toleransi kehilangan data) / *Recovery Time Objective* (target waktu pulih) |
| **SLA** | *Service Level Agreement* — batas waktu layanan yang dijanjikan |
| **Soft delete** | Data ditandai terhapus tetapi masih tersimpan dan dapat dipulihkan |
| **SRS** | *Software Requirements Specification* — spesifikasi kebutuhan perangkat lunak |
| **SSR** | *Server-Side Rendering* — halaman disusun di server; baik untuk SEO & kecepatan |
| **Story Point (SP)** | Satuan relatif besarnya pekerjaan, bukan satuan waktu |
| **Repository (lapis)** | Satu kelas per entitas yang menjadi **satu-satunya** jalan ke tabel sensitif, dan selalu menerapkan pembatasan wilayah/keluarga |
| **Tenant** | Satu organisasi (desa) dalam sistem multi-tenant |
| **UAT** | *User Acceptance Testing* — pengujian penerimaan oleh pengguna |
| **WCAG** | *Web Content Accessibility Guidelines* — standar aksesibilitas web |

## Status dalam Sistem

**Pengajuan surat:** `draft` → `diajukan` → `perlu_revisi` → `diverifikasi` →
`diterbitkan` · `ditolak` · `dibatalkan`

**Penduduk:** `aktif` · `meninggal` · `pindah_keluar` · `hilang` · `tidak_diketahui`

**Pengaduan:** `baru` → `diterima` → `diproses` → `selesai` · `ditolak`

**Akun pengguna:** `menunggu_verifikasi` → `aktif` · `nonaktif` · `terkunci`

**Konten portal:** `draft` → `terbit` → `arsip`

**Anggaran (Fase 2):** `draft` → `final` → `publikasi`
