# 00 — Log Keputusan Proyek (Decision Log)

Catatan keputusan yang sudah **final** beserta alasan dan konsekuensinya. Dokumen lain
mengikuti keputusan di sini. Perubahan keputusan wajib lewat Change Request (01 §9).

---

## KEP-001 — Lingkup: **satu desa** (single-tenant)
**Tanggal:** 2026-10-04 · **Status:** Disetujui · **Pemutus:** Pemilik proyek

**Keputusan:** Sistem dibangun untuk **satu desa**. Tidak ada multi-tenancy.

**Konsekuensi positif:**
- Skema basis data lebih sederhana: **tidak ada kolom `tenant_id`** di mana pun.
- Tidak perlu Row-Level Security, *global scope* tenant, atau middleware tenant.
- Risiko kebocoran lintas desa — kelas bug paling berbahaya pada sistem multi-tenant —
  **hilang sepenuhnya**.
- Lapis uji otorisasi menyusut dari (peran × endpoint × 6 skenario kepemilikan) menjadi
  (peran × endpoint × 4 skenario). Hemat ±1 sprint.
- Volume data kecil (± 5.000 penduduk) → banyak kekhawatiran kinerja tidak berlaku.

**Konsekuensi negatif / yang dikorbankan:**
- Bila kelak ingin melayani beberapa desa dalam satu instalasi, perlu **retrofit**:
  tambah `tenant_id` ke ±18 tabel, tambahkan ke indeks komposit, tambahkan *global
  scope*, dan **uji ulang seluruh otorisasi**. Perkiraan 15–25 hari-orang.
- Mitigasi risiko retrofit: semua akses data wajib lewat satu lapis *repository/service*
  (04 §3.3), sehingga filter tenant kelak hanya ditambahkan di satu tempat, bukan
  tersebar di kontroler. **Ini satu-satunya syarat arsitektural yang wajib dijaga
  sejak Sprint 1.**

**Jalur alternatif bila multi-desa ternyata dibutuhkan ≤ 12 bulan:** pasang instalasi
terpisah per desa (database + container sendiri). Untuk < 10 desa ini sering **lebih
murah dan lebih aman** daripada retrofit multi-tenancy.

> Bila sejak sekarang sudah pasti akan ada lebih dari satu desa, beri tahu — lebih baik
> `tenant_id` ditambahkan di Sprint 1 daripada di-retrofit nanti.

---

## KEP-002 — Basis data: **MySQL 8.0**
**Tanggal:** 2026-10-04 · **Status:** Disetujui · **Pemutus:** Pemilik proyek

**Keputusan:** MySQL 8.0 (InnoDB, `utf8mb4_0900_ai_ci`), menggantikan rekomendasi
awal PostgreSQL.

**Mengapa ini sekarang pilihan yang wajar:** keberatan utama terhadap MySQL pada
dokumen 04 adalah **tidak adanya Row-Level Security** untuk isolasi antar-desa.
Dengan KEP-001, kebutuhan itu tidak ada lagi. Sisa perbedaan bersifat teknis dan
seluruhnya punya padanan di MySQL 8.

**Padanan fitur (rinci di 05 §9):**
| Kebutuhan | PostgreSQL | MySQL 8 — padanan yang dipakai |
|-----------|-----------|-------------------------------|
| NIK unik hanya untuk penduduk aktif | *partial unique index* | Kolom *generated* `nik_hash_active` + UNIQUE |
| Satu kepala keluarga per KK | *partial unique index* | Kolom *generated* `family_head_key` + UNIQUE |
| Nomor surat tanpa duplikat & tanpa celah | `SELECT … FOR UPDATE` | **Sama** — InnoDB mendukung `FOR UPDATE` |
| Formulir dinamis | JSONB + GIN | `JSON` + *generated column* + indeks |
| Statistik agregat | *materialized view* | Tabel ringkasan `population_stats` + *job* penyegar |
| Pencarian nama toleran salah ketik | `pg_trgm` | Kolom nama ternormalisasi + `LIKE` (memadai di 5.000 baris) |
| Audit log tak dapat diubah | `REVOKE` + partisi | **`REVOKE` sama**; partisi ditunda (volume kecil) |
| Enkripsi kolom NIK | di lapis aplikasi | **Sama** (di lapis aplikasi, bukan fitur DB) |

**Konsekuensi negatif yang diterima:**
1. **Pencarian nama tidak toleran salah ketik.** "Sitia" tidak akan menemukan "Siti".
   Di 5.000 baris `LIKE '%siti%'` cepat, tetapi tetap pencocokan literal.
   *Mitigasi:* pencarian juga menerima NIK & nomor KK persis; UI menampilkan nama +
   umur + dusun agar petugas yakin memilih orang yang benar.
2. **Isolasi data sepenuhnya bergantung kode aplikasi.** Tidak ada jaring pengaman
   tingkat basis data untuk pembatasan wilayah (Kadus) dan data-sendiri (Warga).
   *Mitigasi:* lapis uji otorisasi (09 §4.3) menjadi **wajib mutlak**, bukan opsional.
3. **Statistik dapat tertinggal** sampai *job* penyegar berjalan (interval 1 jam,
   plus penyegaran langsung setelah impor massal & mutasi).

**Yang tidak berubah:** seluruh aturan bisnis, API, UI/UX, keamanan aplikasi, dan
strategi pengujian. Pergantian basis data **tidak** menyentuh dokumen 02, 03, 06, 07,
08 (kecuali satu bagian), 09, 11.

---

## KEP-003 — Jenis surat: **katalog umum, 8 prioritas + 12 lanjutan**
**Tanggal:** 2026-10-04 · **Status:** Disetujui · **Pemutus:** Pemilik proyek

**Keputusan:** Sistem dikirim dengan katalog jenis surat **umum** yang sudah siap pakai
(dokumen 13), bukan menunggu daftar khusus dari satu desa.

**Konsekuensi:**
- Sprint 4–5 **tidak lagi terblokir** oleh ketersediaan daftar surat dari desa.
- Katalog diisi lewat *seeder* saat pemasangan; petugas dapat mengubah nama, syarat,
  isi template, dan pola nomor **tanpa bantuan programmer** (FR-SRT-01…03).
- **Yang tetap wajib diverifikasi sebelum go-live:** pola penomoran surat dan nama
  pejabat penanda tangan — dua hal yang berbeda di setiap desa dan tidak dapat
  diasumsikan. Selebihnya cukup disesuaikan lewat menu pengaturan.

**Risiko yang tersisa:** format/tata letak surat tiap desa bisa berbeda dari template
bawaan. Karena template berupa HTML yang dapat disunting (ADR-003), penyesuaian ini
adalah pekerjaan konfigurasi (± 0,5 hari-orang/template), bukan pengembangan.

---

## KEP-004 — Stack aplikasi (menyesuaikan KEP-002)
**Tanggal:** 2026-10-04 · **Status:** Disetujui

| Lapis | Pilihan |
|-------|---------|
| Backend | PHP 8.3+ + **Laravel 13** (naik dari rencana Laravel 11 — lihat 14 §4) |
| Basis data | **MySQL 8.0** (KEP-002) |
| Panel admin | Inertia.js + React + TypeScript + Tailwind (mulai Sprint 2) |
| Portal publik | Blade (SSR) |
| Cache / queue / lock | Redis 7 |
| PDF | Gotenberg (Chromium *headless*) |
| Berkas | S3-kompatibel (MinIO atau object storage penyedia) |

Alasan Laravel + MySQL: kombinasi yang paling mudah di-*hosting* di Indonesia, paling
banyak SDM-nya untuk pemeliharaan jangka panjang, dan tidak mengunci desa pada satu
penyedia.

---

## KEP-005 — Panel admin: **Blade + JavaScript minimal**, bukan Inertia/React
**Tanggal:** 2026-10-05 · **Status:** Disetujui (diambil Tech Lead, dapat ditinjau ulang)
**Mengubah:** ADR-006 (docs/04 §5) dan docs/07 §3 yang semula menetapkan Inertia + React

**Keputusan:** panel administrasi dibangun dengan **Blade (SSR) + JavaScript biasa
seperlunya**, memakai design token CSS dari Sprint 1. Tidak ada Inertia, React, Vue,
atau Node pada jalur produksi.

> **Koreksi 2026-10-08:** versi awal keputusan ini menyebut "Blade + Alpine.js".
> Yang benar-benar dibangun pada Sprint 2 adalah Blade + **JavaScript biasa 3 KB**
> (`public/js/sid.js`) — Alpine tidak jadi dipakai karena kebutuhannya ternyata
> hanya dua: membuka data pribadi dan mencegah kirim ganda. Keduanya tidak
> memerlukan kerangka kerja apa pun. Dokumen diluruskan agar sesuai kenyataan.

**Alasan:**
1. **Serah terima (KEP-001, docs/01 §6).** Sistem ini harus dapat dirawat perangkat
   desa atau Diskominfo kabupaten setelah serah terima. Satu bahasa (PHP) dan satu
   kerangka kerja jauh lebih mungkin dirawat daripada PHP + Node + React + proses *build*.
2. **Skala tidak menuntutnya.** 5.000 penduduk dan segelintir operator (docs/05 §8).
   Keunggulan SPA — interaksi tanpa muat ulang pada data besar — tidak terasa di sini,
   sementara biayanya (pipeline build, dua skill set, *bundle* 300 KB) tetap penuh.
3. **Jaringan desa.** Blade SSR memberi halaman utuh dalam satu permintaan; tidak ada
   *bundle* JavaScript yang harus diunduh sebelum layar pertama tampil (NFR-PERF-04).
4. **Sprint 1 sudah menyediakan fondasinya.** Design token dan komponen dasar sudah
   ada dan langsung terpakai, tanpa dikerjakan ulang dalam React.

**Yang dikorbankan:**
- Interaksi kaya (tabel dengan *inline editing*, *drag-drop*) lebih sulit. Untuk
  kebutuhan Sprint 2–5 hal itu tidak diperlukan.
- Pencarian *autocomplete* dan filter memerlukan JavaScript yang ditulis manual
  alih-alih komponen siap pakai.

**Mengapa ini reversibel dengan biaya rendah:** seluruh aturan bisnis berada di lapis
*service* dan *repository* yang tidak mengetahui apa pun tentang UI. Mengganti view
Blade dengan Inertia/React kelak berarti menulis ulang lapis presentasi saja
(perkiraan 15–20 hari-orang), **tanpa** menyentuh logika, otorisasi, atau pengujian.

**Catatan arah ke depan (2026-10-08):** bila kelak dibutuhkan kerangka kerja
JavaScript, **Vue lebih sesuai daripada React** untuk proyek ini — lihat KEP-007.

---

## KEP-006 — Pencadangan kunci data pribadi: **dua pemegang, dicetak di kertas**
**Tanggal:** 2026-10-08 · **Status:** Disetujui · **Pemutus:** Pemilik proyek
**Menutup risiko:** R-18 (docs/14 §5, docs/15 §6)

**Keputusan:** `PII_ENCRYPTION_KEY` dan `PII_HASH_PEPPER` dicadangkan dengan cara
**dicetak/ditulis di kertas**, dipegang **dua orang** secara terpisah.

**Mengapa kertas justru pilihan yang baik di sini — bukan kompromi:**
- Tidak dapat diretas dari jarak jauh. Ancaman terbesar terhadap kunci ini adalah
  penyerang yang sudah masuk ke server atau ke penyimpanan cadangan; kertas di
  brankas berada sepenuhnya di luar jangkauan itu.
- Tidak bergantung pada langganan, listrik, atau perangkat yang bisa rusak.
- Dapat dibaca siapa pun 10 tahun lagi tanpa perangkat lunak khusus.

**Yang dikorbankan:** kertas bisa terbakar, basah, hilang, atau salah dibaca
(angka 0 vs huruf O). Karena itu dua pemegang, dan karena itu disediakan perintah
verifikasi di §Prosedur agar kesalahan penyalinan ketahuan **saat itu juga**,
bukan saat kunci dibutuhkan dalam keadaan darurat.

**Aturan mutlak yang menyertainya:**
1. Kertas **TIDAK BOLEH** disimpan di tempat yang sama dengan cadangan basis data.
   Menyimpannya bersama membuat seluruh enkripsi tidak ada gunanya — seperti
   menempelkan kunci rumah di pintu depan.
2. Kunci **TIDAK BOLEH** dikirim lewat WhatsApp, email, atau difoto. Riwayat
   percakapan dan galeri ponsel adalah tempat kebocoran yang paling umum.
3. Dua pemegang menyimpan di **dua lokasi fisik berbeda** (mis. brankas kantor desa
   dan brankas/tempat terkunci di rumah pemegang kedua). Satu kebakaran tidak boleh
   menghabiskan keduanya.

**Prosedur lengkap:** [ops/runbook/pencadangan-kunci.md](../ops/runbook/pencadangan-kunci.md)

**Kapan wajib dilakukan:** sebelum baris data warga **asli** yang pertama ditulis —
bukan sebelum go-live. Selama isinya masih data contoh, kunci hilang tidak berakibat
apa-apa; tinggal dibuat ulang.

---

## KEP-007 — Bila kelak perlu kerangka kerja JS: **Vue, dan hanya di layar yang membutuhkan**
**Tanggal:** 2026-10-08 · **Status:** Arah yang disepakati (belum dijalankan)
**Melengkapi:** KEP-005

### Keputusan
1. Bila suatu saat dibutuhkan kerangka kerja JavaScript, pilihannya adalah
   **Vue**, bukan React.
2. Penerapannya **tidak menyeluruh**. Vue dipasang sebagai *island* — hanya pada
   layar tertentu yang benar-benar menuntutnya. Sisa aplikasi tetap Blade.
3. Keputusan itu **ditunda sampai Sprint 4**, saat kebutuhan nyatanya muncul.

### Mengapa Vue, bukan React

| Pertimbangan | Vue | React |
|--------------|-----|-------|
| Bentuk kode tampilan | Template mirip HTML — dekat dengan Blade | JSX: HTML ditulis di dalam JavaScript |
| Kurva belajar dari latar PHP | Lebih landai | Lebih terjal |
| Pasangan tradisional Laravel di Indonesia | Vue (bawaan Laravel selama bertahun-tahun) | Belakangan lebih umum secara global, kurang di kalangan Laravel lokal |
| Kemudahan dipakai sebagian saja | Mudah — cukup tempelkan pada satu elemen | Bisa, tetapi lebih berat |

Butir terakhir yang paling menentukan untuk proyek ini: Vue dapat dipasang hanya
pada satu halaman tanpa mengubah apa pun di halaman lain.

### Mengapa *island*, bukan penggantian menyeluruh

Mengganti seluruh panel ke Vue berarti menulis ulang ± 10 layar yang sudah bekerja,
menambahkan Node.js dan proses *build* ke jalur rilis, dan menaikkan jumlah
teknologi yang harus dikuasai perawat sistem dari satu menjadi tiga — demi
keuntungan yang tidak terasa pada layar CRUD biasa.

Pendekatan *island* membayar biaya itu **hanya** di tempat yang memberi imbalan.

### Di mana imbalannya nyata

Satu layar sudah terlihat akan menuntutnya: **penyunting formulir dinamis jenis
surat** (FR-SRT-03, Sprint 4). Di sana petugas menyusun sendiri daftar isian untuk
tiap jenis surat — menambah, menghapus, mengubah urutan, menentukan jenis isian,
dengan pratinjau langsung. Membangun itu dengan JavaScript manual memang bisa,
tetapi akan menjadi bagian kode yang paling rumit dan paling rapuh di seluruh
sistem.

Layar lain yang mungkin menyusul: penyunting template surat (Sprint 5) dan peta
GIS (Fase 3).

Layar yang **tidak** membutuhkannya: seluruh CRUD kependudukan, impor data,
daftar, saringan, laporan, dan portal publik.

### Konsekuensi yang diterima
- Dua gaya penulisan tampilan hidup berdampingan (Blade dan Vue). Dapat
  membingungkan bila batasnya tidak jelas — karena itu aturannya ditulis di sini:
  Vue hanya untuk layar yang terdaftar di atas, dan setiap penambahan memerlukan
  alasan tertulis.
- Proses *build* masuk ke jalur rilis begitu *island* pertama dipasang. Pipeline
  CI perlu tahap tambahan.

### Yang perlu dibereskan lebih dulu
`package.json` saat ini masih memuat Vite dan Tailwind dari kerangka bawaan
Laravel, padahal **tidak ada satu pun view yang memakainya**. Perlu dibersihkan
atau justru dimanfaatkan saat *island* pertama dipasang — jangan dibiarkan
menggantung sebagai ketergantungan yang tidak jelas statusnya.

---

## KEP-008 — Panel admin **dipindahkan ke Vue 3 + Inertia + TypeScript**
**Tanggal:** 2026-10-08 · **Status:** Disetujui · **Pemutus:** Pemilik proyek
**Menggantikan:** KEP-005 · **Melampaui:** KEP-007 (yang menyarankan *island*, bukan penggantian menyeluruh)

**Keputusan:** seluruh panel administrasi dibangun ulang dengan **Vue 3 + Inertia.js
+ TypeScript + Tailwind v4**, menggantikan Blade. Portal publik **tetap Blade (SSR)**
demi SEO dan kecepatan muat pertama (ADR-006 tidak berubah).

**Catatan jujur:** rekomendasi teknis saya adalah KEP-007 — Vue hanya pada layar yang
menuntutnya. Pemilik proyek memilih penggantian menyeluruh setelah trade-off
disampaikan dua kali. Keputusan itu sah dan dicatat apa adanya, berikut biayanya.

**Yang bertambah ke jalur rilis:**
| Sebelum | Sesudah |
|---------|---------|
| PHP + Laravel | PHP + Laravel + Node.js + Vue + TypeScript + proses *build* Vite |
| `git pull && php artisan migrate` | ditambah `npm ci && npm run build` |
| Tanpa artefak *build* | `public/build/` wajib dibangun ulang tiap rilis |

Konsekuensi langsungnya: yang merawat sistem setelah serah terima perlu menguasai
dua ekosistem, bukan satu (lihat alasan asli di KEP-005).

**Yang diperoleh:**
- Interaksi tanpa muat ulang halaman; saringan & pencarian terasa seketika.
- Mode gelap/terang penuh dengan token semantik.
- Komponen antarmuka bertipe (TypeScript) — galat props tertangkap saat *build*,
  bukan saat petugas membuka halaman.
- Fondasi siap untuk layar yang memang menuntutnya nanti (penyunting formulir
  dinamis jenis surat, Sprint 4 — alasan asli KEP-007).

**Yang TIDAK berubah, dan ini yang terpenting:**
Seluruh aturan bisnis, otorisasi, pembatasan wilayah/keluarga, dan audit log berada
di lapis *service*/*repository* yang tidak mengetahui apa pun tentang antarmuka.
Penggantian ini menyentuh **lapis presentasi saja**. Bukti: 132 uji yang ada tetap
berlaku; hanya 1 yang perlu disesuaikan (dari asersi HTML ke asersi props Inertia),
dan justru menjadi lebih kuat.

**Aturan baru yang mengikat:**
1. Data yang dikirim ke peramban harus **sudah siap tampil dan sudah ter-masking**
   (`App\Http\Resources\ResidentPresenter`). NIK apa adanya tidak pernah lewat sana.
2. Kode referensi diterjemahkan di **sisi server**. Memelihara dua daftar label
   (PHP dan TypeScript) berarti keduanya pasti berbeda suatu saat.
3. Dependensi ber-lingkup akses **dilarang** di-inject lewat konstruktor controller —
   lihat KEP-009.

---

## KEP-009 — Dependensi ber-lingkup akses hanya lewat **injeksi metode**
**Tanggal:** 2026-10-08 · **Status:** Wajib · **Kategori:** keamanan

**Masalah yang ditemukan:** `Illuminate\Routing\Route::getController()` menyimpan
instance controller pada objek `Route`, dan objek `Route` hidup selama proses
berjalan. Dependensi yang di-*inject* lewat konstruktor karena itu dibuat **sekali
per proses**, bukan per permintaan.

Untuk repository yang membawa `AccessScope`, akibatnya: **lingkup akses pengguna
pertama ikut terpakai oleh pengguna berikutnya.**

**Mengapa berbahaya justru karena tidak terlihat:**
| Lingkungan | Terpicu? |
|------------|----------|
| PHP-FPM (satu proses, satu permintaan) | Tidak |
| Pengujian HTTP berurutan | Ya |
| **Laravel Octane** | **Ya, pada setiap permintaan kedua dan seterusnya** |

Artinya bug ini dapat tidur sampai seseorang memasang Octane demi kinerja — lalu
Kepala Dusun melihat data seluruh desa, atau Admin Desa justru terkunci di satu dusun.

**Ditemukan bagaimana:** saat verifikasi Sprint 2 Vue, tiga peran berbeda yang
diminta berurutan mengembalikan daftar penduduk yang **sama persis** (4 orang),
padahal lapis repository bila diuji langsung memberi 4 / 7 / 3 dengan benar.

**Keputusan:**
1. Controller **tidak boleh** punya konstruktor yang menerima `ResidentRepository`,
   `FamilyRepository`, `ResidentService`, `FamilyService`, `RegionService`, atau
   `AccessScope`. Semuanya di-*inject* sebagai **parameter metode** — Laravel
   menyelesaikannya pada setiap dispatch.
2. `AccessScope` diikat `bind` (transient), bukan `scoped` atau `singleton`.
3. Ditegakkan `tests/Architecture/RepositoryBoundaryTest.php`, dan diuji perilakunya
   oleh `tests/Authorization/PopulationHttpScopeTest.php`
   (`lingkup_tidak_bocor_antar_permintaan_berurutan`).

Kedua penjaga diverifikasi balik: mengembalikan konstruktor membuat uji arsitektur
gagal dan menyebut berkasnya.
