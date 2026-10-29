# 22 — Laporan Uji Penetrasi Sprint 7

**Proyek:** Sistem Informasi Desa (SID) · **Sprint:** 7 — Pengaduan, laporan,
stabilisasi · **Tanggal:** 24 Oktober 2026 · **Milestone:** M5 "Siap UAT"

Dokumen ini melaporkan uji penetrasi internal atas permukaan serang yang
**baru ditambahkan Sprint 7**: modul pengaduan (kanal publik, portal warga,
panel petugas), dashboard, serta laporan & ekspor.

> **Batasnya jelas.** Ini uji internal yang dikerjakan tim pembangun, bukan
> uji penetrasi pihak ketiga. docs/11 §5 menempatkan uji pihak ketiga sebagai
> paket pekerjaan terpisah, dan docs/09 §4.5 menuntut "0 Critical/High
> terbuka" dari uji itu — bukan dari dokumen ini. Yang dapat dijamin di sini
> hanyalah bahwa kelas serangan di bawah sudah dicoba dan ditutup, dan bahwa
> setiap penutupnya punya uji otomatis yang menahannya tetap tertutup.

---

## 1. Metodologi

Tiga lapis, seluruhnya otomatis dan berjalan di CI:

| Lapis | Berkas | Yang dijaga |
|-------|--------|-------------|
| Sapuan otorisasi seluruh rute | `tests/Authorization/RouteSweepTest.php` | docs/09 §4.3 — setiap peran × setiap endpoint, **dibangkitkan dari tabel rute** |
| Serangan terarah ke modul baru | `tests/Security/ComplaintPenetrationTest.php` | penugasan massal, RULE-09, injeksi, XSS, pembatasan laju, IDOR |
| Skenario kepemilikan | `ComplaintPanelTest`, `ReportTest`, `ScopeMatrixTest`, `PopulationHttpScopeTest` | objek sendiri / wilayah lain / keluarga lain / tidak ada |

**Mengapa sapuannya dibangkitkan, bukan ditulis tangan.** Uji yang ditulis per
layar hanya menjaga layar yang sempat diingat seseorang. Rute yang ditambahkan
enam bulan lagi — oleh orang yang belum pernah membaca docs/09 — tidak akan
punya ujinya, dan justru rute itulah yang paling mungkin lupa memasang
pemeriksaan izin. `RouteSweepTest` membaca tabel rute Laravel, sehingga rute
baru tanpa izin menggagalkan uji dengan sendirinya.

Sapuan itu juga menjaga dirinya sendiri: satu uji memastikan jumlah rute
tersapu tidak nol. Tanpa itu, penyaring rute yang rusak akan membuat seluruh
lapis otorisasi lulus tanpa memeriksa apa pun — dan layarnya tetap hijau.

---

## 2. Temuan

### T-1 — Warga dapat membuka layar PETUGAS dan membaca catatan internal
**Tingkat: TINGGI · Status: DITUTUP**

**Yang ditemukan.** Matriks RBAC docs/03 §4.9 memberi Warga `C,R*` atas
pengaduan dan surat — membuat, dan membaca miliknya sendiri. Izin itu
diterjemahkan menjadi `pengaduan.lihat` dan `surat.lihat`, yang juga merupakan
izin yang dijaga rute **antrean petugas**. Akibatnya warga dapat membuka
`/pengaduan/antrean/{id}` untuk pengaduannya sendiri.

Layar petugas menampilkan **seluruh riwayat**, termasuk catatan koordinasi
yang ditandai `is_visible_to_reporter = false`. Seorang warga yang membuka
pengaduannya sendiri di sana membaca kalimat seperti *"dugaan awal: kabel
dicuri tetangga"* — dugaan yang belum terbukti, yang sengaja disembunyikan
darinya di halaman pelacakan, dan yang dapat berakhir sebagai pertengkaran
antartetangga. Lapis privasi yang dibangun Sprint 7 Bagian 4 utuh di satu
rute dan bocor di rute lain.

**Mengapa luput.** Uji Bagian 3 dan 4 masing-masing menguji rutenya sendiri
dan keduanya benar. Yang tidak diuji adalah **persimpangannya**: satu
pengguna, dua rute, dua tingkat keterbukaan.

**Penutup.** Middleware `petugas` (`App\Http\Middleware\EnsureStaffQueue`),
dipasang pada `/pengaduan/antrean/*` dan `/surat/*`. Ia menolak pengguna yang
lingkup datanya adalah keluarganya sendiri (`AccessScope::isFamilyLimited()`).

Dipilih **lingkup**, bukan nama peran: yang menentukan bukan "bernama warga"
melainkan "lingkup datanya sesempit keluarganya sendiri" — dan pengguna
sesempit itu memang tidak punya urusan di antrean yang disusun untuk melihat
pekerjaan seluruh desa, apa pun nama perannya nanti.

Jawabannya **403, bukan 404**: yang disembunyikan bukan keberadaan sebuah
objek melainkan keberadaan sebuah halaman, dan halaman itu memang ada serta
diketahui umum. Aturan 404 (docs/06 §4) menjaga agar pengguna tidak dapat
menyimpulkan apakah objek tertentu ada; ia tidak berlaku di sini. Pesannya
mengarahkan warga ke portalnya (KEP-012).

**Akibat ikutan yang ikut ditutup.** Menu dan dashboard sebelumnya menampilkan
"Antrean Surat" dan "Antrean Pengaduan" kepada warga — karena `can()` memang
benar. Tautan yang menuntun ke penolakan lebih buruk daripada tautan yang
tidak ada: ia membuat orang menyimpulkan sistemnya rusak. Prop bersama
`family_limited` kini menyembunyikan menu itu, dan `DashboardService` tidak
lagi mengirim antrean kerja kepada warga. Kartu ANGKA pengaduannya tetap ada —
"berapa pengaduan saya yang masih terbuka" adalah keterangan yang sah baginya.

**Uji penahan:** `ComplaintPenetrationTest::catatan_internal_tidak_terbaca_pelapor`,
`ComplaintPanelTest::warga_ditolak_dari_panel_petugas`.

---

### T-2 — Audit log menautkan akun ke pengaduan anonim
**Tingkat: TINGGI · Status: DITUTUP (saat Bagian 3)**

`AuditLogger::log()` dengan model menyimpan `entity_id` beserta `actor_id`,
`actor_name`, `actor_roles`, dan `ip_address`. Pada pengaduan **anonim** yang
dikirim warga yang sedang masuk, satu baris itu berisi tepat tautan yang
dilarang RULE-09 — lengkap dengan namanya, dan dapat dibaca Admin Desa, yaitu
pihak yang paling sering diadukan. CHECK `chk_complaints_anon` tidak menolong:
yang dijaganya tabel `complaints`, bukan audit log.

**Penutup:** `AuditLogger::logWithoutActor()`, yang sengaja **tidak menerima
model** — baris tanpa pelaku tetapi dengan `entity_id` masih dapat ditautkan
lewat `ip_address` dan waktunya.

---

### T-3 — Alamat IP pelapor dapat di-JOIN dengan jejak login
**Tingkat: SEDANG · Status: DITUTUP (saat Bagian 3)**

Audit log mencatat `ip_address` setiap kali seseorang masuk, sehingga
`complaints.submitted_ip` yang mentah membuat pengaduan anonim bernama kembali
lewat dua kueri. Kolomnya diganti `submitted_ip_hash CHAR(64)` berisi
HMAC-SHA256 ber-pepper. FR-PGD-08 hanya membutuhkan perbandingan
sama-tidak-sama, dan itu dapat dijawab tanpa pernah menyimpan alamatnya.

HMAC dan bukan hash biasa: ruang IPv4 hanya 4 miliar nilai, jadi tabel
pelanginya dapat dibangun dalam hitungan menit.

---

### T-4 — Kolom rekap laporan disisipkan ke teks kueri
**Tingkat: RENDAH (tidak pernah terpapar) · Status: DIJAGA**

`ResidentRepository::recapByCategory()` menyisipkan nama kolom ke dalam
`selectRaw` — nama kolom tidak dapat diikat sebagai parameter SQL. Hari ini
pemanggilnya hanya `ReportService` dengan string tetap, sehingga tidak ada
jalur dari HTTP. Daftar putih tetap dipasang beserta alasannya, dan PHPDoc-nya
sengaja `string` (bukan daftar nilai yang dipersempit) supaya daftar putih itu
benar-benar diperiksa dan tidak dianggap berlebihan oleh analisis statis.

---

## 3. Kelas serangan yang dicoba dan GAGAL menembus

| Serangan | Hasil | Yang menahannya |
|----------|-------|-----------------|
| Menyelundupkan `status`, `is_published`, `priority`, `resolution` ke formulir pengaduan publik | Diabaikan | Layanan menyusun atributnya sendiri, tidak meneruskan masukan |
| Menanamkan pengaduan atas nama warga lain lewat `reporter_user_id` | Diabaikan | idem |
| Memilih sendiri `tracking_code` / `ticket_number` | Diabaikan | Keduanya dibangkitkan server |
| Menerbitkan ke portal lewat rute ubah status | Diabaikan | Rute hanya membaca `status` & `note` |
| Membuat pengaduan anonim yang tetap menyimpan nama & telepon | Dibuang | RULE-09 ditegakkan dengan menghilangkan jalurnya, + CHECK basis data |
| Membaca `tracking_code` dari layar petugas / dashboard / laporan | Tidak pernah dikirim | `ComplaintPresenter` tidak memuatnya di luar tanda terima |
| Menebak kode pelacakan secara beruntun | 429 | `throttle:6,1` pada rutenya |
| Membanjiri kotak pengaduan | 429 + batas per-jaringan | `throttle:5,60` + hitungan baris per sidik jari IP |
| Injeksi SQL lewat pencarian antrean (`' OR '1'='1`, `; DROP TABLE`) | 0 baris, tabel utuh | Eloquent binding |
| XSS lewat judul/isi pengaduan | Tidak menjadi elemen | Vue mengikat teks lewat interpolasi, bukan `v-html` |
| Menebak id pengaduan dusun lain | 404 | Lingkup repository (docs/06 §4) |
| Mengunduh CSV tanpa `laporan.ekspor` | 403 | Middleware izin pada rutenya |
| Mencetak buku induk tanpa `penduduk.ekspor` | 403 | idem |

> Uji injeksi menyertakan **kasus kendali** yang membuktikan pembacaan
> hasilnya memang dapat melihat baris. Tanpa itu, uji yang memeriksa
> "hasilnya kosong" akan lulus dengan sendirinya bila cara membaca hasilnya
> keliru.

---

## 4. Yang BELUM diuji, dan harus dikatakan

| Hal | Mengapa belum | Rencana |
|-----|----------------|---------|
| Uji penetrasi pihak ketiga | Paket pekerjaan terpisah (docs/11 §5) | Sebelum go-live; kriteria "0 Critical/High" docs/09 §4.5 berlaku pada uji itu |
| DAST (OWASP ZAP) otomatis di CI | Belum dipasang di pipeline | Tambahkan ke CI malam |
| Pemindaian dependensi berkala | Berjalan manual | Jadwalkan |
| Uji beban & volume | Dikerjakan terpisah | Lihat sesi/berkas uji beban Sprint 7 |
| Pemulihan bencana (TC-OPS-BACKUP) | Di luar lingkup sprint ini | Pasca Fase 1 minggu 2 |

---

## 5. Rekomendasi

1. **Jalankan `RouteSweepTest` sebagai gerbang rilis.** docs/09 §4.3 menyebut
   kegagalan lapis otorisasi "memblokir rilis"; uji inilah penegaknya.
2. **Perlakukan persimpangan rute sebagai kelas bug tersendiri.** T-1 lolos
   bukan karena satu rute salah, melainkan karena dua rute yang masing-masing
   benar memberi tingkat keterbukaan berbeda atas objek yang sama. Setiap kali
   sebuah objek punya DUA layar — satu untuk pemiliknya, satu untuk petugas —
   tanyakan siapa yang dapat membuka keduanya.
3. **Pertahankan aturan "null, bukan nol"** pada dashboard dan laporan. Angka
   nol bagi orang yang tidak berhak melihatnya adalah pernyataan yang salah,
   dan di layar pimpinan kesalahan seperti itu berakhir sebagai keputusan.
