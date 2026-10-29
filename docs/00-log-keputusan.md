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

---

## KEP-010 — Waktu disimpan UTC, **keputusan kalender memakai zona desa**
**Tanggal:** 2026-10-15 · **Status:** Wajib · **Kategori:** korektness data

**Masalah yang ditemukan:** aplikasi berjalan dan menyimpan waktu dalam UTC — itu
benar untuk penyimpanan. Tetapi beberapa keputusan harus memakai **kalender di
kantor desa**, dan keduanya berbeda tepat di saat-saat yang paling merugikan.

| Keputusan | Bila memakai UTC |
|-----------|------------------|
| Tahun & bulan pada nomor surat | Surat 1 Januari pukul 02.00 WIB bernomor `…/XII/2026` padahal buku agenda desa mencatatnya sebagai surat pertama 2027 |
| Tahun penghitung nomor | Berbeda dari tahun yang tercetak → nomor 1 tahun baru terbit dua kali |
| `letters.issued_year` (generated `YEAR(issued_at)`) | Indeks unik `uq_letters_seq` menjaga pasangan tahun yang salah, sehingga **jaring pengaman terakhir terhadap nomor ganda tidak berfungsi** |
| "Akhir hari kerja" untuk SLA | Jatuh pukul 07.00 pagi berikutnya di Indonesia; laporan SLA menganggap surat terlambat masih tepat waktu |
| Tanggal tercetak di surat | Surat bertanggal 31 Desember sementara nomornya menyebut Januari — dokumen bertentangan dengan dirinya sendiri |

**Mengapa tidak tertangkap pengujian:** seluruh uji penomoran lolos. Mereka memeriksa
hal yang benar, dan jawabannya memang benar — sepanjang tahun. Cacatnya hanya muncul
pada satu malam dalam setahun. Ditemukan dengan menelusuri alur persuratan di
peramban dan membaca batas waktu yang tertulis di layar.

**Keputusan:**
1. Penyimpanan tetap UTC (`APP_TIMEZONE=UTC`). Tidak diubah.
2. `App\Support\VillageTime` menjadi **satu-satunya** sumber kalender desa. Zonanya
   diambil dari `village_profile.timezone`, **bukan** ditulis tetap `Asia/Jakarta` —
   Indonesia punya tiga zona waktu (WIB/WITA/WIT).
3. Yang WAJIB melewatinya: tahun penghitung nomor, token `{bulan}`/`{tahun}`,
   `letters.issued_year`, `valid_until`, tanggal tercetak, dan batas waktu SLA.
4. `letters.issued_year` bukan lagi *generated column*. Ia **ditulis aplikasi** dari
   sumber yang sama dengan penomoran (migrasi `2026_01_03_000200`), agar jaring
   pengaman basis data tidak bergantung pada zona waktu server.
5. Label sisa waktu ("Jatuh tempo besok") disusun **di server**, bukan dihitung
   peramban: perangkat petugas memakai zona waktunya sendiri.

Diuji oleh `tests/Feature/Letter/LetterTimezoneTest.php` dan **diverifikasi balik**:
mengembalikan `VillageTime::zone()` ke `'UTC'` menggagalkan empat uji.

---

## KEP-011 — Badan surat disusun server dan **disimpan utuh**
**Tanggal:** 2026-10-15 · **Status:** Wajib · **Kategori:** arsitektur, kepatuhan

**Masalah yang ditemukan:** tata letak surat (dok. 13 §2) terdiri dari kop, pembuka,
blok identitas, isi pokok, dan penutup. Hanya **isi pokok** yang berasal dari
template yang disunting petugas; sisanya dibentuk sistem.

Semula bagian sistem itu dirakit halaman cetak Vue dari kolom `snapshot`. Itu
melanggar ADR-003 secara halus: **menyunting halaman Vue akan mengubah tampilan surat
yang sudah terbit bertahun-tahun lalu**, padahal arsip harus tetap sama seperti saat
ditandatangani. Dua cacat nyata lahir dari situ — blok identitas menampilkan NIK
tersamar (tidak sah untuk dokumen yang dicocokkan dengan KTP), dan judul jabatan
tercetak "Kepala Desa Desa Sukamaju".

**Keputusan:**
1. `App\Services\Letter\LetterBodyComposer` menyusun badan surat **utuh** saat
   penerbitan; hasilnya disimpan apa adanya di `letters.rendered_html`.
2. Halaman cetak **tidak merakit bagian mana pun** dari badan surat. Ia hanya
   menambahkan kop desa (yang memang boleh mengikuti identitas desa terkini) dan
   blok tanda tangan dari kolom yang tersimpan di surat itu sendiri.
3. NIK **lengkap** masuk ke badan surat — itu memang isi surat keterangan desa.
   `snapshot` tetap menyimpan versi tersamar, dan pengungkapannya **selalu** tercatat
   di audit log (RULE-07), tanpa syarat.
4. `snapshot` tidak dikirim ke peramban: seluruh isinya sudah ada di
   `rendered_html`.

---

## KEP-012 — Penolakan izin **wajib terlihat** di antarmuka Inertia
**Tanggal:** 2026-10-15 · **Status:** Wajib · **Kategori:** kegunaan, keamanan

**Masalah yang ditemukan:** Inertia menangani balasan 403/409 atas sebuah POST dengan
**tidak mengubah apa pun di layar**. Petugas menekan tombol, dan tidak terjadi
apa-apa — lalu menekannya berulang kali dan menyimpulkan sistemnya rusak.

Seluruh pengujian tetap hijau: uji memeriksa **kode status**, dan kode statusnya
memang benar. Yang membedakan permintaan biasa dari permintaan Inertia hanyalah
header `X-Inertia`.

**Keputusan:**
1. Penolakan 403/409 pada permintaan Inertia non-GET dialihkan kembali ke halaman
   asal dengan pesan kesalahan yang tampil sebagai notifikasi
   (`bootstrap/app.php`).
2. Tindakan yang **bukan kewenangan** pengguna tidak ditampilkan sebagai tombol,
   melainkan diganti keterangan siapa yang berwenang — petugas perlu tahu kepada
   siapa berkasnya diteruskan, bukan sekadar bahwa ia ditolak.
3. Ini kenyamanan, **bukan** kontrol keamanan. Penegakannya tetap di lapis layanan
   dan repository.
4. Uji yang memeriksa perilaku ini **wajib** mengirim header `X-Inertia`; tanpa itu,
   ia menguji jalur yang berbeda dari yang dipakai petugas.

---

## KEP-013 — Lingkup Sprint 5 diluruskan, dan PDF dirender lewat antarmuka berdriver
**Tanggal:** 2026-10-15 · **Status:** Wajib · **Kategori:** lingkup, arsitektur

**Masalah yang ditemukan:** dua dokumen memberi jawaban berbeda tentang isi Sprint 5.
`docs/11 §2` menjadwalkan arsip, pembatalan, verifikasi publik, dan alur berjenjang
di S5 — padahal keempatnya sudah selesai di Sprint 4 (`docs/18 §5`), karena surat
yang dapat terbit tetapi tidak dapat dicari atau diperiksa keasliannya belum dapat
diperagakan. Sementara `docs/18 §6` menaruh pengajuan daring oleh warga di S5,
padahal `docs/11` menaruhnya di S6 bersama registrasi dan verifikasi akun warga —
prasyarat yang tanpanya pengajuan daring tidak punya pemilik yang terverifikasi.

**Keputusan:**
1. Sprint 5 berisi **sisa nyata** persuratan: render PDF + QR, unggah berkas syarat,
   dan pelacakan status pengajuan.
2. **Pengajuan daring oleh warga tetap Sprint 6**, satu paket dengan registrasi dan
   verifikasi akun warga. Memisahkannya menghasilkan pengajuan tanpa identitas yang
   dapat dipercaya.
3. Render PDF memakai **antarmuka `PdfRenderer` dengan dua driver**, bukan panggilan
   langsung ke Gotenberg. Produksi memakai Gotenberg (KEP-004, ADR-003); pengembangan
   di macOS memakai Chrome *headless* setempat, karena mesin pengembangan tidak
   selalu punya runtime kontainer dan menunggu Docker terpasang akan menghentikan
   pekerjaan yang tidak ada hubungannya dengan kontainer.
4. Keluaran kedua driver **wajib diperiksa dengan surat yang sama**: perbedaan tata
   letak antara mesin pengembang dan produksi adalah kelas bug yang hanya muncul
   setelah surat dicetak di kantor desa.

## KEP-014 — Pelacakan status surat: publik, tetapi berfaktor dua
**Tanggal:** 2026-10-16 · **Status:** Wajib · **Kategori:** keamanan, privasi

**Masalah yang ditemukan:** FR-SRT-13/BR-11 meminta warga dapat memantau status
pengajuan beserta alasan penolakan. Jalur termudah — halaman publik yang membuka
pengajuan dari nomor tiket, seperti halaman verifikasi keaslian surat — TIDAK aman
di sini. Nomor tiket berbentuk `T261015-C507E9`: tanggal pengajuan ditambah enam
karakter acak, jadi ruang terkaannya kecil dan tanggalnya bukan rahasia. Kode
verifikasi surat 32 karakter acak dan karena itu boleh berdiri sendiri (lihat
`LetterRepository::findByVerificationCode()`); nomor tiket tidak. Halaman tiket-saja
akan memungkinkan seseorang memetakan siapa mengajukan surat apa — termasuk SKTM,
yang keberadaannya saja sudah menyatakan keadaan ekonomi sebuah keluarga.

**Pilihan yang dipertimbangkan:** (a) mewajibkan login; (b) nomor tiket + faktor
kedua milik pemohon.

**Keputusan:**
1. Dipilih **(b): nomor tiket + tanggal lahir pemohon**, keduanya menjadi bagian
   KUERI (`LetterRequestRepository::findByTicketForTracking()`), bukan diperiksa
   setelah barisnya dimuat. Login ditolak sebagai syarat karena akun warga baru ada
   di Sprint 6 (KEP-013 §2), sementara pengajuan loket hari ini dibuat atas nama
   warga yang belum punya akun — BR-11 akan menjadi fitur yang tidak dapat dipakai
   siapa pun sampai sprint berikutnya.
2. Tanggal lahir **bukan rahasia kuat** dan tidak diperlakukan sebagai rahasia: ia
   hanya berlaku bersama tiket yang tepat, rutenya dibatasi `throttle:8,1` (lebih
   ketat daripada `30,1` pada verifikasi surat), dan setiap kegagalan dicatat ke
   audit log sebagai `letter.tracking_failed`.
3. Kegagalan **tidak membedakan** "tiket tidak ada" dari "tanggal lahir salah".
   Jawaban yang berbeda akan mengubah halaman ini menjadi alat untuk memastikan
   sebuah nomor tiket memang terdaftar.
4. Payload pelacakan ditulis **terpisah** dari `LetterRequestPresenter::detail()`
   (`::tracking()`), bukan detail yang dipangkas: layar petugas akan terus
   bertambah isinya, dan setiap tambahan di sana akan ikut terkirim ke halaman
   publik tanpa ada yang meninjaunya. Yang dikirim: status, estimasi selesai,
   riwayat waktu tiap tahap, alasan penolakan / catatan revisi, nama tersamar.
   Yang tidak pernah dikirim: NIK (termasuk tersamar), isi formulir, alamat, dan
   nama petugas pemeriksa.
5. Saat akun warga tersedia di Sprint 6, halaman ini **tetap ada** sebagai "cek
   resi" tanpa login; daftar pengajuan milik akun sendiri menjadi jalur kedua,
   bukan pengganti.

---

## KEP-015 — Mutasi dari SKL/SKM: tawaran berstatus, dan mengapa kelahiran butuh satu layar
**Tanggal:** 2026-10-16 · **Status:** Wajib · **Kategori:** integritas data, alur kerja

**Masalah yang ditemukan:** docs/13 §5.4 dan §5.5 meminta sistem **menawarkan**
pencatatan mutasi saat SKL/SKM terbit — "satu klik, data sudah terisi dari
formulir". Saat dibangun, dua hal di dalam permintaan itu ternyata saling
bertentangan dengan keadaan basis data:

1. `residents.nik` adalah kolom WAJIB dan unik di antara penduduk aktif
   (RULE-01, indeks `uq_residents_nik_active`). Bayi baru lahir belum punya NIK
   — dan NIK itulah yang justru sedang diurus dengan SKL. Jadi "satu klik"
   untuk kelahiran mustahil tanpa memalsukan NIK sementara, yang akan menjadi
   duplikat permanen begitu Dukcapil menerbitkan NIK yang sebenarnya.
2. "Sudah dicatat" dapat diturunkan dari ada-tidaknya mutasi yang menunjuk
   surat tersebut, tetapi "sudah dilihat dan sengaja DITOLAK" tidak dapat
   diturunkan dari apa pun.

**Pilihan yang dipertimbangkan:** (a) mencatat mutasi otomatis saat surat
terbit; (b) tawaran tanpa status, dihitung dari ada-tidaknya mutasi;
(c) tawaran berstatus pada tabelnya sendiri.

**Keputusan:**
1. Dipilih **(c)**: tabel `letter_mutation_offers`, satu baris per pengajuan,
   berstatus `menunggu` / `diterima` / `ditolak`. Pilihan (b) ditolak karena
   tawaran yang sudah ditolak akan muncul kembali setiap kali layar dibuka;
   petugas belajar mengabaikannya, dan itu menghapus seluruh manfaatnya.
2. **(a) ditolak, dan penolakan ini mengikat.** Mutasi mengubah status
   penduduk, dan untuk kematian ia tidak dapat ditarik kembali lewat
   antarmuka. Surat yang keliru masih dapat dibatalkan (RULE-05); status
   penduduk yang keliru mengubah statistik, laporan ke kecamatan, dan
   kelayakan bantuan. Keputusan sebesar itu ditekan oleh orang, bukan menjadi
   akibat samping penerbitan surat. Selain itu kelahiran/kematian sering sudah
   dicatat lewat modul kependudukan lebih dulu — otomatis berarti mutasi
   ganda. `MutationOfferTest` menjaga aturan ini: penerbitan SKL tidak
   mendaftarkan penduduk, dan penerbitan SKM tidak mengubah status siapa pun.
3. Tawaran dibuat **setelah** surat terbit, di dalam transaksi penerbitan, dan
   **tidak diperiksa di mana pun** oleh mesin status pengajuan. Menolak
   tawaran karena itu tidak dapat menghalangi penerbitan — warga yang
   kehilangan anggota keluarga tidak boleh menunggu di loket karena petugas
   belum siap memutuskan mutasi.
4. **Kematian benar-benar satu klik**; **kelahiran mendapat satu layar
   isian** (`Surat/TawaranMutasi/Kelahiran`) yang seluruh kolomnya sudah
   terisi dari formulir surat kecuali NIK bayi. Layar itu menyatakan dengan
   tegas: bila NIK belum ada, tolak tawaran dan catat kelahirannya setelah
   akta keluar — jangan mengisi angka sementara.
5. Kolom selain NIK dan nama anak **ditampilkan, tidak dapat disunting**:
   nilainya sudah tercetak pada surat yang terbit, dan data penduduk yang
   berbeda dari surat atas peristiwa yang sama adalah selisih baru, bukan
   perbaikan. Nama anak dikecualikan karena SKL sering memuat nama sementara
   ("Bayi Ny. …") sementara data penduduk harus memuat nama resminya.
6. **Dua izin, bukan satu.** Rutenya dijaga `mutasi.tambah`; menerima tawaran
   kelahiran menuntut `penduduk.tambah` sebagai tambahan, karena yang terjadi
   adalah pendaftaran penduduk baru. Akibatnya nyata: Kasi Pemerintahan —
   pemeriksa SKL/SKM — dapat mencatat kematian dan menolak tawaran, tetapi
   tidak dapat mendaftarkan bayi; layarnya menjelaskan itu alih-alih
   menampilkan tombol yang akan berakhir 403.
7. Almarhum pada SKM diambil lewat `ResidentRepository` yang **berlingkup**,
   bukan dari relasi pengajuan: `resident_meninggal_id` hanyalah isian
   formulir dan lingkupnya belum pernah diperiksa. Di luar lingkup → **404**,
   bukan 403 (docs/06 §4), dan panelnya tampil tanpa data warga tersebut.
8. Setiap mutasi hasil tawaran menyimpan `letter_id` dan nomor surat desa pada
   `document_no`, sehingga pertanyaan "atas dasar apa status warga ini
   berubah?" dapat dijawab dari catatan mutasinya sendiri — tanpa menebak dari
   kedekatan waktu dengan surat yang terbit hari itu.
9. Surat yang **dibatalkan** tidak dapat lagi menjadi dasar mutasi (RULE-05):
   tanpa pagar ini, surat yang dibatalkan justru karena datanya keliru masih
   dapat dipakai mengubah status penduduk.

---

## KEP-016 — Portal publik dirender Blade, bukan Inertia/Vue
**Tanggal:** 2026-10-18 · **Status:** Wajib · **Kategori:** arsitektur, kinerja

**Masalah yang ditemukan:** KEP-008 memindahkan panel administrasi ke Vue 3 +
Inertia, dan dua halaman publik yang lebih dulu ada (verifikasi keaslian surat
dan pelacakan status) ikut dibangun dengan Inertia. Saat portal publik mulai
dikerjakan, dua ketentuan yang selama ini belum pernah diuji menjadi mengikat
sekaligus: `docs/07 §7` mewajibkan halaman portal **berfungsi tanpa
JavaScript** (*progressive enhancement*), dan `docs/07 §8` menetapkan LCP
≤ 2,5 detik pada 4G dengan transfer pertama ≤ 500 KB (NFR-PERF-03/04).
Inertia yang dirender di peramban tidak dapat memenuhi keduanya: tanpa
JavaScript halamannya kosong — bukan sekadar kurang rupawan — dan paket Vue
saja sudah memakan sebagian besar anggaran 500 KB sebelum satu kata pun dari
isi desa ikut terkirim.

**Pilihan yang dipertimbangkan:** (a) Inertia seperti panel, menerima halaman
kosong tanpa JS; (b) Inertia + SSR; (c) Blade yang dirender server.

**Keputusan:**
1. Dipilih **(c)**. Portal publik — beranda, profil, pemerintahan, wilayah,
   layanan, statistik, kontak — dirender Blade dan tidak memuat satu berkas
   JavaScript pun. Isinya sudah utuh pada HTML pertama.
2. **(b) ditolak.** Inertia SSR memperbaiki keduanya, tetapi menuntut satu
   proses Node berjalan terus di produksi, dipantau, dan dihidupkan ulang
   bila mati. Sistem ini harus dapat dirawat perangkat desa setelah serah
   terima (docs/01 §6) — alasan yang sama yang melahirkan KEP-005 dan membuat
   KEP-007 menyarankan Vue hanya pada layar yang membutuhkannya. Portal yang
   seluruhnya teks dan tabel adalah justru layar yang **tidak** membutuhkannya.
3. **(a) ditolak** karena bukan penurunan mutu yang anggun melainkan kegagalan
   total: pengunjung dengan JS gagal dimuat melihat halaman putih di situs
   resmi desanya. Pada jaringan desa itu bukan kemungkinan teoretis.
4. Gayanya tetap **satu sumber**: Blade memakai `resources/css/app.css` yang
   sama dengan panel. Padanan Blade dibuat hanya untuk komponen yang memang
   dipakai portal (sudah ada `<x-alert>`), bukan menyalin seluruh pustaka Vue.
   Ikon digambar sebagai SVG sebaris; portal tidak memuat paket ikon.
5. **Batasnya jelas:** halaman publik yang berupa FORMULIR dengan keadaan
   validasi — verifikasi keaslian surat dan pelacakan status — tetap Inertia
   dan tidak ditulis ulang. Keduanya sudah berjalan dan berujinya lengkap,
   bukan halaman yang dituju mesin pencari, dan menulis ulang kode yang sudah
   benar bukan pekerjaan gratis. Yang mengikat adalah arahnya: halaman portal
   **baru** dibangun dengan Blade.
6. Portal punya kerangka `<head>` **sendiri**, terpisah dari `app.blade.php`.
   Akar Inertia memasang `noindex, nofollow` untuk seluruh panel administrasi;
   portal justru harus terindeks (FR-PRT-08). Memakai satu akar untuk keduanya
   berarti satu baris meta yang menentukan apakah situs desa muncul di mesin
   pencari atau tidak — dan baris itu akan berubah karena alasan yang tidak ada
   hubungannya dengan portal.
7. `sitemap.xml` dan `robots.txt` **dibangkitkan**, bukan berkas statis di
   `public/`: daftar layanan berubah dari panel petugas tanpa rilis baru, dan
   baris `Sitemap:` menuntut URL absolut yang tidak diketahui sebuah berkas
   statis. `PublicPortalTest` menjaga agar panel administrasi dan halaman
   pelacakan tidak pernah masuk ke peta situs.

**Konsekuensi yang diterima:** "statistik interaktif" pada FR-PRT-05 menjadi
grafik batang CSS dengan tabel angka di bawahnya, bukan grafik yang dapat
disaring di peramban. Penyaringan per wilayah memang tidak dikehendaki di
halaman publik (ADR-008, lihat §3 keterangan di `portal/statistik.blade.php`),
sehingga yang hilang hanyalah animasi — sementara yang didapat adalah halaman
yang terbaca pembaca layar dan tetap hidup tanpa JavaScript.

---

## KEP-017 — Pendaftaran warga: satu jawaban untuk semua hasil
**Tanggal:** 2026-10-18 · **Status:** Wajib · **Kategori:** keamanan, privasi

**Masalah yang ditemukan:** FR-AUT-08 meminta pendaftaran warga mencocokkan
NIK + nomor KK + nama ke data penduduk, dan FR-AUT-10 melarang sistem
mengungkap apakah suatu NIK terdaftar. Keduanya bertabrakan pada hal yang
paling lumrah di setiap formulir pendaftaran: pesan galat yang menolong.
"NIK tidak ditemukan", "NIK ini sudah punya akun", dan "email sudah dipakai"
masing-masing menjawab pertanyaan yang tidak berhak ditanyakan orang asing.
NIK berpola — enam digit pertamanya kode wilayah, enam berikutnya tanggal
lahir — sehingga ruang tebakannya jauh lebih kecil daripada enam belas digit
acak. Formulir yang membedakan jawabannya karena itu bukan sekadar bocor
sedikit: ia dapat dipakai menyusun daftar penduduk desa, lengkap dengan
tanggal lahirnya, tanpa pernah masuk ke sistem.

**Pilihan yang dipertimbangkan:** (a) pesan galat spesifik, dengan pembatasan
laju sebagai satu-satunya pagar; (b) satu jawaban seragam untuk semua hasil;
(c) verifikasi lewat OTP ke nomor ponsel lebih dulu, baru pencocokan.

**Keputusan:**
1. Dipilih **(b)**. Setiap pengiriman formulir berakhir di halaman
   `daftar/terkirim` yang sama persis — cocok, tidak cocok, penduduk sudah
   punya akun, atau kontak sudah dipakai. `CitizenRegistrationService::register()`
   karena itu tidak mengembalikan apa pun dan tidak melempar pengecualian
   untuk ketidakcocokan.
2. **Harga yang dibayar diakui, dan dibayar di tempat lain.** Warga yang salah
   mengetik satu huruf namanya akan menunggu tanpa tahu apa yang keliru. Maka
   halaman konfirmasi WAJIB memuat jalan keluar untuk setiap kemungkinan:
   tautan atur ulang kata sandi bagi yang mungkin sudah punya akun, dan ajakan
   datang ke kantor desa membawa KTP dan KK bagi yang datanya tidak cocok.
   Halaman itu bagian dari keputusan ini, bukan hiasan — tanpanya pilihan (b)
   memindahkan beban ke warga dan berhenti di situ.
3. **Yang TETAP dijawab terang** adalah galat tentang data pemohon sendiri:
   kata sandi terlalu pendek, email dan nomor ponsel dua-duanya kosong, foto
   KTP bukan gambar. Tak satu pun menyatakan apa pun tentang warga lain, dan
   membungkamnya hanya membuat formulir terasa rusak.
4. Pencocokan menuntut **ketiganya cocok** dan penduduknya **aktif** (RULE-03).
   NIK saja tidak cukup: ia beredar di fotokopi KTP yang tertinggal di mana-
   mana, sedangkan nomor KK beserta ejaan nama adalah dua hal yang praktis
   hanya diketahui orang di dalam keluarga itu.
5. Pencocokan **mengabaikan lingkup akses** (`findByNikIgnoringScope`), dan
   memang harus: pemohon adalah tamu tanpa AccessScope sama sekali. Yang
   menjaga keamanannya bukan lingkup melainkan tiga syarat serentak di atas,
   ditambah `throttle:5,60` pada rutenya.
6. Percobaan yang tidak cocok dicatat sebagai `citizen.registration_unmatched`
   dengan **NIK ter-hash**, bukan terbaca. Petugas dapat melihat ada berapa
   banyak percobaan gagal dan mengenali pola percobaan berurutan; audit log
   tidak menjadi tempat penyimpanan NIK baru di luar tabel penduduk.
7. **(c) ditolak** untuk Fase 1: pengiriman OTP menuntut layanan SMS/WhatsApp
   berbayar, yang dilarang docs/03 §2.4 pada MVP, dan menutup warga yang
   mendaftar dengan email saja.
8. **Foto KTP dihapus begitu petugas memutuskan**, disetujui maupun ditolak.
   Ia hanya diperlukan untuk satu keputusan, dan keputusan itu sudah diambil.
   Menyimpannya "untuk jaga-jaga" berarti desa memelihara arsip foto KTP
   seluruh warganya — sasaran yang lebih berharga bagi penyerang daripada
   basis datanya sendiri, dan tidak diminta satu pun kebutuhan (docs/08 §3).
9. Verifikasi dijaga `pengguna.verifikasi`, yang pada matriks RBAC hanya
   dimiliki Admin Desa, dan rutenya menolak akun yang bukan warga menunggu
   verifikasi dengan **404**. Tanpa pagar itu, rute ini menjadi cara
   mengaktifkan akun apa pun yang kebetulan berstatus menunggu — termasuk
   akun petugas yang sengaja dinonaktifkan.

---

## KEP-018 — Pengaduan: dua kolom tenggat, dan sidik jari IP alih-alih alamatnya
**Tanggal:** 2026-10-22 · **Status:** Wajib · **Kategori:** model data, privasi

**Masalah yang ditemukan:** skema `complaints` pada docs/05 §3.5 menyediakan
**satu** kolom tenggat (`due_at`) dan satu kolom alamat IP (`submitted_ip`).
Keduanya ternyata tidak cukup ketika FR-PGD-06 dan RULE-09 dikerjakan
bersamaan.

*Tenggat.* FR-PGD-06 menetapkan **dua** batas yang berjalan berurutan: triase
2 hari dan penyelesaian 14 hari kerja. Satu kolom memang cukup untuk
menampilkan "terlambat" hari ini — maknanya tinggal berpindah mengikuti status
— tetapi ia **menimpa** angka tenggat triase begitu pengaduan ditriase.
Akibatnya pertanyaan "berapa persen pengaduan ditriase tepat waktu" hanya
dapat dijawab untuk pengaduan yang BELUM ditangani, yaitu justru yang belum
punya hasil. Ketepatan triase adalah satu-satunya angka yang menunjukkan
apakah pengaduan warga dibaca orang, dan ia hilang tepat ketika pengaduannya
selesai.

*Alamat IP.* FR-PGD-08 menuntut pembatasan laju per IP, dan skema menyimpan
alamatnya mentah. Tetapi audit log mencatat `ip_address` setiap kali seseorang
masuk. Alamat mentah di tabel pengaduan karena itu dapat **di-JOIN** dengan
baris login: pengaduan anonim tentang pungutan liar menjadi bernama kembali
lewat dua kueri, oleh orang yang berhak membaca audit log — yaitu Admin Desa,
yang justru paling sering menjadi pihak yang diadukan. CHECK
`chk_complaints_anon` tidak menolong sama sekali, karena kolom itu memang tidak
dilarangnya.

**Pilihan yang dipertimbangkan:** (a) tetap satu kolom tenggat yang maknanya
berpindah, dan terima hilangnya angka ketepatan triase; (b) tambah kolom
`triage_due_at`; (c) tabel riwayat tenggat tersendiri. Untuk IP: (d) simpan
mentah dan andalkan kebijakan agar tidak di-JOIN; (e) jangan simpan apa pun
dan andalkan middleware `throttle` saja; (f) simpan sidik jari ber-kunci.

**Keputusan:**
1. Dipilih **(b)**. Kolom `triage_due_at DATETIME NULL` ditambahkan. Ia diisi
   **sekali** saat pengaduan masuk dan **tidak pernah diubah lagi**, termasuk
   setelah ditriase — yang membuatnya berguna justru keabadiannya.
   `due_at` tetap seperti pada docs/05, diisi saat triase.
   `Complaint::activeDueAt()` yang memutuskan mana yang sedang ditunggu.
   **(c) ditolak** karena satu tabel tambahan untuk dua angka per baris adalah
   harga yang tidak sebanding, dan ia menambah satu gabungan pada setiap
   tampilan antrean.
2. **Tenggat penyelesaian dihitung dari saat TRIASE**, bukan dari saat
   pengaduan masuk. Pengaduan yang didiamkan sepuluh hari lalu baru ditriase
   tidak lantas tersisa empat hari: itu menghukum petugas yang akhirnya
   menanganinya dan menyembunyikan keterlambatan yang sebenarnya terjadi di
   tahap triase. Keterlambatan itu punya catatannya sendiri, dan itulah yang
   harus dibaca bila angka SLA desa terlihat buruk.
3. Dipilih **(f)**. `submitted_ip VARCHAR(45)` diganti
   `submitted_ip_hash CHAR(64)` berisi HMAC-SHA256 ber-pepper (`PiiCipher`).
   Yang dibutuhkan FR-PGD-08 hanyalah perbandingan sama-tidak-sama, dan itu
   dapat dijawab tanpa pernah menyimpan alamatnya.
   **HMAC dan bukan hash biasa**, dengan alasan yang sama seperti NIK: ruang
   IPv4 hanya 4 miliar nilai, sehingga tabel pelanginya dapat dibangun dalam
   hitungan menit dan hash biasa tidak melindungi apa pun bila basis data
   bocor. **(e) ditolak** karena middleware `throttle` menghitung permintaan
   HTTP dan lupa setiap kali cache dibersihkan; hitungan baris tetap berlaku
   melewati penyetelan ulang cache.
4. **Batas SLA-nya sendiri dibaca dari tabel `settings`**
   (`complaint.triage_sla_days`, `complaint.resolution_sla_days`) dengan
   bawaan FR-PGD-06, bukan dari `config/sid.php`: ia aturan bisnis yang boleh
   berbeda per desa. Yang di `config/sid.php` hanya batas teknis per jaringan.
5. **Keterlambatan SELALU dihitung kueri, tidak pernah disimpan.** Perintah
   `sid:eskalasi-pengaduan` hanya menaikkan prioritas dan mengirim notifikasi
   — dua hal yang memang menuntut pemicu. Konsekuensinya disengaja: penjadwal
   yang mati membuat desa kehilangan PERINGATAN, bukan kehilangan kebenaran,
   dan tidak ada satu angka pun di layar yang menjadi salah karenanya. Pola
   yang sama dengan penjadwalan terbit konten portal (Sprint 6).

**Konsekuensi yang diterima:** docs/05 §3.5 tidak lagi menggambarkan tabel
`complaints` secara persis — dua kolomnya berbeda. Dokumen model data harus
diperbarui pada penyelarasan berikutnya; sampai itu terjadi, entri ini adalah
sumber kebenarannya. Satu kolom tambahan juga berarti satu indeks tambahan
(`idx_complaints_triage_due`), yang dibayar pada setiap penyisipan pengaduan —
jumlah yang kecil dibanding nilai angka ketepatan triase.

Konsekuensi kedua, lebih halus: `submitted_ip_hash` tidak dapat dibaca
manusia. Perangkat desa yang ingin tahu "pengaduan ini datang dari mana" tidak
akan pernah mendapat jawabannya dari basis data, dan memang tidak boleh —
pertanyaan itu sendiri adalah bentuk pembukaan keanoniman.

---

## KEP-019 — Indeks penutup untuk halaman Laporan, dan mengapa indeks terpisah ditolak
**Tanggal:** 2026-10-24 · **Status:** Wajib · **Kategori:** kinerja, model data

**Masalah yang ditemukan:** uji beban & volume Sprint 7
(`docs/23-uji-beban-sprint-7.md`) menyemai 20.000 penduduk — volume sasaran
resmi proyek (NFR-PERF-07) — lalu mengukur setiap halaman. Satu halaman
melewati NFR-PERF-01 (p95 baca ≤ 500 ms) **sendirian**:
`ReportService::population()` terukur **755 ms**.

Sebabnya bukan satu kueri yang rumit, melainkan **enam kali membaca tabel yang
sama**. Halaman Laporan menampilkan rekap per wilayah dan per lima kategori
(jenis kelamin, agama, pendidikan, pekerjaan, status perkawinan). Setiap rekap
menyaring `status = 'aktif' AND deleted_at IS NULL`, lalu membaca **satu kolom
lain** per baris. Indeks `idx_residents_status` hanya memuat kedua kolom
penyaring itu, sehingga kolom kategorinya harus diambil dari indeks berkluster
— 19.600 pencarian baris acak, per rekap, dengan kunci primer CHAR(36).
Terukur 70–88 ms untuk setiap kategori, dan 370 ms untuk rekap per wilayah
yang bahkan menghidrasi 20.000 objek Eloquent hanya untuk menambahkannya ke
tiga penghitung.

Yang pantas dicatat adalah **mengapa ini tidak pernah terlihat sampai Sprint
7**: basis data pengembangan berisi 500 penduduk (docs/09 §3), dan pada 500
baris keenam pembacaan itu masing-masing 2 ms. Halaman ini tidak pernah
melambat di mana pun yang dilihat orang — ia hanya akan melambat di desa,
setelah impor data kependudukan yang sebenarnya.

**Pilihan yang dipertimbangkan:**
(a) biarkan, dan andalkan cache halaman;
(b) tabel ringkasan seperti `population_stats` (ADR-007) untuk laporan juga;
(c) indeks penutup **baru** khusus rekap;
(d) **lebarkan** `idx_residents_status` yang sudah ada;
(e) satukan kelima rekap menjadi satu kueri `GROUP BY` kombinasi, tanpa
    indeks baru.

**Keputusan: (d) + (e), dua-duanya.**

1. **`idx_residents_status` dilebarkan** dari `(status, deleted_at)` menjadi
   `(status, deleted_at, region_id, gender, religion_code, education_code,
   occupation_code, marital_status_code)` — migrasi
   `2026_01_08_000100_widen_residents_status_index_for_reports.php`.
2. **Kelima rekap kategori menjadi SATU kueri**
   (`ResidentRepository::recapByCategories()`), dikelompokkan menurut
   kombinasi kelima kolom lalu dijumlahkan per kolom di PHP — pekerjaan di
   bawah satu milidetik atas ratusan baris kombinasi.
3. **Rekap per wilayah dihitung basis data** (`GROUP BY region_id, gender`,
   ± 24 baris), bukan dengan memuat seluruh penduduk. Pemetaan RT → dusun
   memakai satu kueri tabel `regions` dan segmen pertama kolom `path`.

Hasil terukur pada 20.000 penduduk, mesin yang sama:

| | sebelum | sesudah |
|---|---|---|
| rekap per wilayah | 370 ms | 10 ms |
| rekap lima kategori | 385 ms | 22 ms |
| `ReportService::population()` | 755 ms | 43 ms |
| halaman `/laporan`, waktu basis data | 495 ms | 66 ms |

**Mengapa (c) ditolak, dan ini alasan yang hanya muncul setelah diukur:**
indeks penutup terpisah memang membuat kelima kueri lama menjadi 10–22 ms —
**tetapi hanya dengan `FORCE INDEX`**. Pengoptimasi MySQL tetap memilih
`idx_residents_status` yang lebih sempit karena biaya perkiraannya lebih
rendah, dan `EXPLAIN` menunjukkan indeks baru itu tidak pernah dipakai dengan
sendirinya. Petunjuk indeks di dalam Eloquent adalah janji yang patah tanpa
suara pada versi MySQL berikutnya, dan kegagalannya tidak berupa galat
melainkan halaman yang perlahan melambat — kelas bug yang tidak pernah
terlihat di CI. Melebarkan indeks yang **sudah** dipilih menghapus pilihan
yang perlu dipaksakan. Nama indeksnya pun sengaja dipertahankan, karena dua
kolom pertamanya tetap sama persis sehingga tidak ada satu pun rencana kueri
lain yang berubah.

**Mengapa (b) ditolak:** `population_stats` memang sudah ada, tetapi ia
**mengabaikan lingkup akses** dengan sengaja (ADR-007: ia hanya menyajikan
agregat publik). Laporan internal WAJIB mengikuti lingkup — Kepala Dusun
merekap dusunnya sendiri (docs/04 §4.1) — sehingga ia memerlukan tabel
ringkasan per wilayah per peran, yaitu cache yang harus dibatalkan setiap kali
satu penduduk berubah. Satu indeks lebih sederhana dan tidak dapat kedaluwarsa.

**Mengapa (a) ditolak:** laporan yang di-cache adalah laporan yang angkanya
berbeda dari layar penduduk di sebelahnya, dan perangkat desa akan menemukan
selisih itu pada saat yang paling buruk — di rapat.

**Konsekuensi yang diterima:**

1. **Enam kolom tambahan (± 110 byte per baris) dibayar pada setiap
   penyisipan dan setiap perubahan salah satu kolom itu.** Yang ditukar
   disengaja: di satu desa, perubahan data penduduk berjumlah puluhan per
   hari dan tidak ada yang menungguinya; halaman Laporan dibuka beberapa kali
   sehari oleh orang yang menunggu di depan layar.
2. **Impor massal menjadi sedikit lebih lambat.** Impor 20.000 baris
   memperbarui satu indeks yang lebih lebar. Pada penyemai volume, selisihnya
   tidak terukur di atas derau.
3. **docs/05-model-data.md §3.2 masih menuliskan indeks yang lama.** Sampai
   dokumen itu diselaraskan, entri ini adalah sumber kebenarannya.
4. **Daftar putih kolom rekap kini dipakai di dua tempat** (satu kolom dan
   beberapa kolom). Nama kolom tidak dapat diikat sebagai parameter SQL, jadi
   daftar putih itu satu-satunya yang memisahkan laporan dari injeksi SQL;
   ia karena itu dijaga uji sendiri
   (`tests/Feature/Report/ReportRecapQueryTest.php`).
