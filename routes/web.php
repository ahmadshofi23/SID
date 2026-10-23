<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Citizen\CitizenVerificationController;
use App\Http\Controllers\Citizen\RegistrationController;
use App\Http\Controllers\Citizen\WargaController;
use App\Http\Controllers\Complaint\ComplaintController;
use App\Http\Controllers\Complaint\PublicComplaintController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Letter\LetterAttachmentController;
use App\Http\Controllers\Letter\LetterController;
use App\Http\Controllers\Letter\LetterPdfController;
use App\Http\Controllers\Letter\LetterRequestController;
use App\Http\Controllers\Letter\LetterTrackingController;
use App\Http\Controllers\Letter\LetterTypeController;
use App\Http\Controllers\Letter\LetterVerificationController;
use App\Http\Controllers\Letter\MutationOfferController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Population\AnomalyController;
use App\Http\Controllers\Population\FamilyController;
use App\Http\Controllers\Population\ImportController;
use App\Http\Controllers\Population\MutationController;
use App\Http\Controllers\Population\RegionController;
use App\Http\Controllers\Population\ResidentController;
use App\Http\Controllers\Population\StatisticsController;
use App\Http\Controllers\Portal\PortalController;
use App\Http\Controllers\Portal\PostController;
use App\Http\Controllers\Portal\PwaController;
use App\Http\Controllers\Portal\SitemapController;
use App\Http\Controllers\Report\ReportController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Rute Web
|--------------------------------------------------------------------------
| URL berbahasa Indonesia secara sengaja: perangkat desa dan warga melihat
| dan kadang mengetikkan URL ini (NFR-USA-01).
*/

/* ---------------------------- Portal publik ----------------------------
 | TANPA otentikasi: inilah muka desa bagi warga dan instansi lain
 | (docs/03 §2.1, BR-20). Dirender Blade, bukan Inertia — docs/07 §7
 | mewajibkan halaman portal berfungsi tanpa JavaScript dan §8 membatasi
 | transfer awal 500 KB; alasan lengkapnya di KEP-016.
 |
 | Yang menjaga rute-rute ini bukan middleware melainkan PILIHAN SUMBER
 | DATA: statistik dibaca dari tabel ringkasan `population_stats`, perangkat
 | desa dari `officials`, dan tidak satu pun menyentuh `residents`.
 */
Route::controller(PortalController::class)->group(function (): void {
    Route::get('/', 'home')->name('portal.beranda');
    Route::get('profil', 'profil')->name('portal.profil');
    Route::get('profil/wilayah', 'wilayah')->name('portal.wilayah');
    Route::get('profil/pemerintahan', 'pemerintahan')->name('portal.pemerintahan');
    Route::get('layanan', 'layanan')->name('portal.layanan');
    Route::get('layanan/{kode}', 'layananDetail')->name('portal.layanan.detail');
    Route::get('statistik', 'statistik')->name('portal.statistik');
    Route::get('kontak', 'kontak')->name('portal.kontak');

    /*
     | Konten portal (FR-PRT-02/03). Satu rute detail untuk ketiga jenis,
     | dengan jenisnya terikat pada ruas URL: pengumuman yang dibuka lewat
     | /berita/{slug} menghasilkan 404, supaya satu tulisan hanya punya satu
     | alamat yang dapat dibagikan dan diindeks.
     */
    Route::get('berita', 'berita')->name('portal.berita');
    Route::get('pengumuman', 'pengumuman')->name('portal.pengumuman');
    Route::get('agenda', 'agenda')->name('portal.agenda');
    Route::get('{jenis}/{slug}', 'tulisan')
        ->whereIn('jenis', ['berita', 'pengumuman', 'agenda'])
        ->name('portal.tulisan');
});

/*
 | Peta situs & robots dibangkitkan, bukan berkas statis: daftar layanan
 | berubah dari panel petugas, dan baris `Sitemap:` menuntut URL absolut yang
 | tidak diketahui berkas di public/. Lihat SitemapController.
 */
/*
 | Aplikasi web progresif (docs/03 §2.3, docs/07 §8). Manifest dibangkitkan
 | karena nama desa ada di basis data; halaman luring berupa rute biasa agar
 | service worker dapat meng-cache-nya lewat URL yang sama dengan yang
 | dilihat pengguna. Service worker sendiri berkas statis di public/sw.js —
 | ia WAJIB disajikan dari akar agar lingkupnya mencakup seluruh situs.
 */
Route::get('manifest.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');
Route::get('luring', [PwaController::class, 'offline'])->name('pwa.luring');

Route::get('sitemap.xml', [SitemapController::class, 'sitemap'])->name('portal.sitemap');
Route::get('robots.txt', [SitemapController::class, 'robots'])->name('portal.robots');

/* ------------------------ Verifikasi surat (publik) ---------------------
 | TANPA otentikasi: dipakai bank, sekolah, dan kantor kecamatan yang
 | menerima surat dari warga (FR-SRT-14). Dibatasi laju karena kode
 | verifikasi dapat dicoba-coba — lihat LetterVerificationController.
 */
Route::get('surat/verifikasi/{kode}', LetterVerificationController::class)
    ->middleware('throttle:30,1')
    ->name('surat.verifikasi');

/* ------------------ Pelacakan status pengajuan (publik) -----------------
 | TANPA otentikasi karena akun warga baru ada di Sprint 6 (KEP-013 §2),
 | sementara pengajuan loket hari ini dibuat atas nama warga yang belum
 | punya akun — BR-11 tidak dapat dipenuhi bila halaman ini meminta login.
 |
 | Gantinya: faktor kedua (tanggal lahir pemohon) dan laju yang LEBIH KETAT
 | daripada verifikasi surat — nomor tiket `T261015-C507E9` hanya memuat enam
 | karakter acak, jadi ia dapat dicoba-coba. Alasan lengkapnya ada di
 | LetterTrackingController (KEP-014).
 */
Route::get('surat/lacak', [LetterTrackingController::class, 'show'])
    ->name('surat.lacak');
Route::post('surat/lacak', [LetterTrackingController::class, 'track'])
    ->middleware('throttle:8,1')
    ->name('surat.lacak.cari');

/* --------------------------- Pengaduan (publik) -------------------------
 | FR-PGD-01, FR-PGD-02, UC-13. TANPA otentikasi: matriks RBAC docs/03 §4.9
 | memberi PB (publik) "C(anonim)" atas pengaduan, dan hak mengadu tidak boleh
 | bergantung pada punya tidaknya akun — orang yang paling perlu mengadu
 | seringkali orang yang paling tidak terdaftar di mana pun.
 |
 | Inertia, bukan Blade, dan itu TIDAK melanggar KEP-016: §5-nya menetapkan
 | batasnya pada formulir dengan keadaan validasi. Alasan lengkapnya ada di
 | PublicComplaintController.
 |
 | LAJU DIBATASI DI DUA TEMPAT, dan keduanya perlu:
 |   - `throttle:5,60` pada pengiriman — lima pengaduan per jam per IP. Pagar
 |     kasar yang menahan banjir dari satu skrip, tetapi ia menghitung
 |     permintaan HTTP dan lupa setiap kali cache dibersihkan.
 |   - hitungan baris per IP di ComplaintService, yang membaca pengaduan yang
 |     benar-benar tersimpan dan karena itu tetap berlaku melewati
 |     penyetelan ulang cache.
 |
 | Pelacakan dibatasi lebih ketat daripada pelacakan surat (`throttle:8,1`):
 | kode pelacakan adalah SATU-SATUNYA faktor pembuka pengaduan, jadi tidak ada
 | faktor kedua yang memperlambat terkaan.
 */
Route::get('pengaduan', [PublicComplaintController::class, 'create'])
    ->name('pengaduan.buat');
Route::post('pengaduan', [PublicComplaintController::class, 'store'])
    ->middleware('throttle:5,60')
    ->name('pengaduan.kirim');

Route::get('pengaduan/lacak', [PublicComplaintController::class, 'showTracking'])
    ->name('pengaduan.lacak');
Route::post('pengaduan/lacak', [PublicComplaintController::class, 'track'])
    ->middleware('throttle:6,1')
    ->name('pengaduan.lacak.cari');

/* --------------------------- Tamu (belum masuk) ------------------------- */
Route::middleware('guest')->group(function (): void {
    Route::get('masuk', [LoginController::class, 'show'])->name('login');
    Route::post('masuk', [LoginController::class, 'store'])
        ->middleware('throttle:20,1');   // pagar kasar; jeda bertingkat di LoginThrottle

    Route::get('masuk/dua-faktor/{challenge}', [TwoFactorController::class, 'challenge'])
        ->name('two-factor.challenge');
    Route::post('masuk/dua-faktor/{challenge}', [TwoFactorController::class, 'verifyChallenge'])
        ->middleware('throttle:10,1')
        ->name('two-factor.verify');

    /* ---------------------- Pendaftaran akun warga ---------------------
     | FR-AUT-08/09/10, UC-15. Prasyarat pengajuan surat daring (KEP-013 §2).
     |
     | Laju dibatasi KETAT (5 per jam per IP): formulir ini mencocokkan NIK
     | ke data penduduk, sehingga tanpa pagar laju ia dapat dipakai menebak
     | NIK berurutan. Jawabannya memang selalu sama (KEP-017), tetapi
     | pembatasan laju menutup pengukuran waktu dan percobaan massal.
     */
    Route::get('daftar', [RegistrationController::class, 'show'])->name('daftar');
    Route::post('daftar', [RegistrationController::class, 'store'])
        ->middleware('throttle:5,60')
        ->name('daftar.store');
    Route::get('daftar/terkirim', [RegistrationController::class, 'sent'])
        ->name('daftar.terkirim');

    Route::get('lupa-sandi', [PasswordResetController::class, 'showRequestForm'])
        ->name('password.request');
    Route::post('lupa-sandi', [PasswordResetController::class, 'sendResetLink'])
        ->middleware('throttle:10,60')
        ->name('password.email');

    Route::get('atur-sandi/{token}', [PasswordResetController::class, 'showResetForm'])
        ->name('password.reset');
    Route::post('atur-sandi', [PasswordResetController::class, 'resetPassword'])
        ->name('password.update');
});

/* ------------------------- Terotentikasi ------------------------------- */
Route::middleware('auth')->group(function (): void {
    Route::post('keluar', [LoginController::class, 'destroy'])->name('logout');

    // Pemasangan 2FA — harus dapat diakses walau EnforceTwoFactor aktif.
    Route::get('dua-faktor/pasang', [TwoFactorController::class, 'setup'])
        ->name('two-factor.setup');
    Route::post('dua-faktor/pasang', [TwoFactorController::class, 'confirm'])
        ->name('two-factor.confirm');

    Route::get('dashboard', DashboardController::class)->name('dashboard');

    /* ---------------------- Kependudukan (Sprint 2) --------------------- */
    Route::prefix('kependudukan')->group(function (): void {

        // Penduduk
        Route::get('penduduk', [ResidentController::class, 'index'])
            ->middleware('izin:penduduk.lihat')->name('penduduk.index');
        Route::get('penduduk/tambah', [ResidentController::class, 'create'])
            ->middleware('izin:penduduk.tambah')->name('penduduk.create');
        Route::post('penduduk', [ResidentController::class, 'store'])
            ->middleware('izin:penduduk.tambah')->name('penduduk.store');
        Route::get('penduduk/cari', [ResidentController::class, 'suggest'])
            ->middleware('izin:penduduk.lihat')->name('penduduk.suggest');
        Route::get('penduduk/{id}', [ResidentController::class, 'show'])
            ->middleware('izin:penduduk.lihat')->name('penduduk.show');
        Route::get('penduduk/{id}/ubah', [ResidentController::class, 'edit'])
            ->middleware('izin:penduduk.ubah')->name('penduduk.edit');
        Route::put('penduduk/{id}', [ResidentController::class, 'update'])
            ->middleware('izin:penduduk.ubah')->name('penduduk.update');
        Route::delete('penduduk/{id}', [ResidentController::class, 'destroy'])
            ->middleware('izin:penduduk.hapus')->name('penduduk.destroy');

        // Pembukaan NIK penuh — izin terpisah, selalu tercatat (RULE-07)
        Route::post('penduduk/{id}/nik', [ResidentController::class, 'revealNik'])
            ->middleware('izin:data_pribadi.lihat_penuh')->name('penduduk.reveal-nik');

        // Kartu keluarga
        Route::get('keluarga', [FamilyController::class, 'index'])
            ->middleware('izin:keluarga.lihat')->name('keluarga.index');
        Route::get('keluarga/tambah', [FamilyController::class, 'create'])
            ->middleware('izin:keluarga.tambah')->name('keluarga.create');
        Route::post('keluarga', [FamilyController::class, 'store'])
            ->middleware('izin:keluarga.tambah')->name('keluarga.store');
        Route::get('keluarga/{id}', [FamilyController::class, 'show'])
            ->middleware('izin:keluarga.lihat')->name('keluarga.show');
        Route::get('keluarga/{id}/ubah', [FamilyController::class, 'edit'])
            ->middleware('izin:keluarga.ubah')->name('keluarga.edit');
        Route::put('keluarga/{id}', [FamilyController::class, 'update'])
            ->middleware('izin:keluarga.ubah')->name('keluarga.update');
        Route::delete('keluarga/{id}', [FamilyController::class, 'destroy'])
            ->middleware('izin:keluarga.hapus')->name('keluarga.destroy');
        Route::post('keluarga/{id}/pindah-anggota', [FamilyController::class, 'moveMember'])
            ->middleware('izin:keluarga.ubah')->name('keluarga.move-member');
        Route::post('keluarga/{id}/nomor-kk', [FamilyController::class, 'revealKkNumber'])
            ->middleware('izin:data_pribadi.lihat_penuh')->name('keluarga.reveal-kk');

        // Mutasi penduduk (Sprint 3)
        Route::get('mutasi', [MutationController::class, 'index'])
            ->middleware('izin:mutasi.lihat')->name('mutasi.index');
        Route::get('penduduk/{id}/mutasi', [MutationController::class, 'create'])
            ->middleware('izin:mutasi.tambah')->name('mutasi.create');
        Route::post('penduduk/{id}/mutasi', [MutationController::class, 'store'])
            ->middleware('izin:mutasi.tambah')->name('mutasi.store');

        // Statistik (Sprint 3)
        Route::get('statistik', [StatisticsController::class, 'index'])
            ->middleware('izin:penduduk.lihat')->name('statistik.index');
        Route::post('statistik/segarkan', [StatisticsController::class, 'refresh'])
            ->middleware('izin:laporan.lihat')->name('statistik.refresh');

        // Laporan anomali data (Sprint 3)
        Route::get('anomali', [AnomalyController::class, 'index'])
            ->middleware('izin:penduduk.lihat')->name('anomali.index');

        // Impor massal (Sprint 3)
        Route::get('impor', [ImportController::class, 'index'])
            ->middleware('izin:penduduk.impor')->name('impor.index');
        Route::get('impor/template', [ImportController::class, 'template'])
            ->middleware('izin:penduduk.impor')->name('impor.template');
        Route::post('impor', [ImportController::class, 'store'])
            ->middleware('izin:penduduk.impor')->name('impor.store');
        Route::get('impor/{id}', [ImportController::class, 'show'])
            ->middleware('izin:penduduk.impor')->name('impor.show');
        Route::get('impor/{id}/status', [ImportController::class, 'status'])
            ->middleware('izin:penduduk.impor')->name('impor.status');
        Route::post('impor/{id}/jalankan', [ImportController::class, 'confirm'])
            ->middleware('izin:penduduk.impor')->name('impor.confirm');
        Route::post('impor/{id}/batal', [ImportController::class, 'cancel'])
            ->middleware('izin:penduduk.impor')->name('impor.cancel');

        // Ekspor (Sprint 3)
        Route::get('penduduk-ekspor', [ResidentController::class, 'export'])
            ->middleware('izin:penduduk.ekspor')->name('penduduk.export');

        // Wilayah
        Route::get('wilayah', [RegionController::class, 'index'])
            ->middleware('izin:wilayah.lihat')->name('wilayah.index');
        Route::get('wilayah/tambah', [RegionController::class, 'create'])
            ->middleware('izin:wilayah.kelola')->name('wilayah.create');
        Route::post('wilayah', [RegionController::class, 'store'])
            ->middleware('izin:wilayah.kelola')->name('wilayah.store');
        Route::get('wilayah/{region}/ubah', [RegionController::class, 'edit'])
            ->middleware('izin:wilayah.kelola')->name('wilayah.edit');
        Route::put('wilayah/{region}', [RegionController::class, 'update'])
            ->middleware('izin:wilayah.kelola')->name('wilayah.update');
        Route::delete('wilayah/{region}', [RegionController::class, 'destroy'])
            ->middleware('izin:wilayah.kelola')->name('wilayah.destroy');
    });

    /* -------------------------- Persuratan (Sprint 4) -------------------- */
    Route::prefix('surat')->group(function (): void {

        // Antrean & pemrosesan pengajuan
        Route::get('/', [LetterRequestController::class, 'index'])
            ->middleware('izin:surat.lihat')->name('surat.index');
        Route::get('ajukan', [LetterRequestController::class, 'create'])
            ->middleware('izin:surat.ajukan')->name('surat.create');
        Route::post('/', [LetterRequestController::class, 'store'])
            ->middleware('izin:surat.ajukan')->name('surat.store');

        // Arsip surat terbit — DIDAFTARKAN SEBELUM `{id}` agar tidak
        // tertangkap sebagai UUID pengajuan.
        Route::get('arsip', [LetterController::class, 'index'])
            ->middleware('izin:surat.lihat')->name('surat.arsip');
        Route::get('arsip/{id}/cetak', [LetterController::class, 'print'])
            ->middleware('izin:surat.lihat')->name('surat.cetak');
        Route::post('arsip/{id}/batalkan', [LetterController::class, 'cancel'])
            ->middleware('izin:surat.batalkan')->name('surat.batalkan');

        // Unduhan PDF (Sprint 5). Lingkup wilayah/keluarga diperiksa di
        // setiap permintaan lewat LetterRepository — tidak ada URL
        // pra-tanda-tangan yang dapat beredar lepas dari pemeriksaan itu.
        Route::get('arsip/{id}/pdf', [LetterPdfController::class, 'download'])
            ->middleware('izin:surat.lihat')->name('surat.pdf');
        Route::post('arsip/{id}/pdf/ulangi', [LetterPdfController::class, 'retry'])
            ->middleware('izin:surat.lihat')->name('surat.pdf-ulangi');

        Route::get('{id}', [LetterRequestController::class, 'show'])
            ->middleware('izin:surat.lihat')->name('surat.show');
        Route::put('{id}', [LetterRequestController::class, 'update'])
            ->middleware('izin:surat.ubah')->name('surat.update');

        Route::post('{id}/verifikasi', [LetterRequestController::class, 'verify'])
            ->middleware('izin:surat.verifikasi')->name('surat.verifikasi-pengajuan');
        Route::post('{id}/revisi', [LetterRequestController::class, 'requestRevision'])
            ->middleware('izin:surat.verifikasi')->name('surat.revisi');
        Route::post('{id}/tolak', [LetterRequestController::class, 'reject'])
            ->middleware('izin:surat.verifikasi')->name('surat.tolak');
        Route::post('{id}/batalkan', [LetterRequestController::class, 'cancel'])
            ->middleware('izin:surat.ubah')->name('surat.batalkan-pengajuan');

        /*
         * Lampiran syarat (Sprint 5, KEP-013 §1).
         *
         * Berkas dialirkan lewat aplikasi, bukan lewat URL pra-tanda-tangan
         * penyimpanan: lingkup wilayah/keluarga harus diperiksa pada SETIAP
         * permintaan, dan URL yang beredar lepas tidak dapat diperiksa lagi.
         */
        Route::post('{id}/lampiran', [LetterAttachmentController::class, 'store'])
            ->middleware('izin:surat.ubah')->name('surat.lampiran.store');
        Route::get('{id}/lampiran/{berkas}', [LetterAttachmentController::class, 'show'])
            ->middleware('izin:surat.lihat')->name('surat.lampiran.show');
        Route::delete('{id}/lampiran/{berkas}', [LetterAttachmentController::class, 'destroy'])
            ->middleware('izin:surat.ubah')->name('surat.lampiran.destroy');

        // Penerbitan: di sinilah nomor surat terbit (RULE-04, ADR-001).
        Route::post('{id}/terbitkan', [LetterRequestController::class, 'issue'])
            ->middleware('izin:surat.setujui')->name('surat.terbitkan');

        /*
         * Tawaran mutasi dari SKL/SKM (Sprint 5, KEP-015, docs/13 §5.4-5.5).
         *
         * Berizin `mutasi.tambah`, BUKAN `surat.*`: yang dilakukan di sini
         * adalah mengubah data kependudukan, dan kewenangan itu tidak ikut
         * dengan kewenangan memproses surat. Petugas surat yang tidak
         * berwenang mencatat mutasi tetap melihat tawarannya sebagai
         * keterangan, tanpa tombol.
         */
        Route::get('{id}/tawaran-mutasi', [MutationOfferController::class, 'create'])
            ->middleware('izin:mutasi.tambah')->name('surat.tawaran-mutasi.create');
        Route::post('{id}/tawaran-mutasi', [MutationOfferController::class, 'store'])
            ->middleware('izin:mutasi.tambah')->name('surat.tawaran-mutasi.store');
        Route::post('{id}/tawaran-mutasi/tolak', [MutationOfferController::class, 'decline'])
            ->middleware('izin:mutasi.tambah')->name('surat.tawaran-mutasi.tolak');
    });

    /* ------------------------ Jenis surat & template --------------------- */
    /* -------------------- Notifikasi (Sprint 6) ------------------------
     | FR-SRT-14, BR-11. Satu layar untuk petugas maupun warga: isinya
     | berbeda, perilakunya identik.
     */
    Route::get('notifikasi', [NotificationController::class, 'index'])->name('notifikasi.index');
    Route::post('notifikasi/{id}/baca', [NotificationController::class, 'read'])->name('notifikasi.baca');
    Route::post('notifikasi/baca-semua', [NotificationController::class, 'readAll'])->name('notifikasi.baca-semua');

    /* ------------------------ Portal warga (Sprint 6) -------------------
     | UC-05, FR-SRT-04, BR-10 — docs/07 §2.2.
     |
     | Dijaga middleware `warga`: berperan warga, akun aktif, dan tertaut ke
     | satu data penduduk. Pembatasan ke dirinya sendiri + satu KK (RULE-11)
     | TIDAK ditegakkan di sini melainkan di lapis repository — pengajuan
     | keluarga lain tidak terlihat, sehingga menjadi 404 dengan sendirinya.
     |
     | Unduhan surat memakai rute arsip yang sudah ada (`surat.pdf`):
     | LetterRepository berlingkup keluarga dan peran `warga` memegang
     | `surat.lihat`, jadi rute kedua hanya akan menjadi jalur kedua yang
     | harus ikut diperiksa setiap kali aturan aksesnya berubah.
     */
    Route::get('warga/belum-aktif', [WargaController::class, 'belumAktif'])
        ->name('warga.belum-aktif');

    Route::prefix('warga')->middleware('warga')->group(function (): void {
        Route::get('/', [WargaController::class, 'beranda'])->name('warga.beranda');
        Route::get('keluarga', [WargaController::class, 'keluarga'])->name('warga.keluarga');

        Route::get('ajukan', [WargaController::class, 'ajukan'])->name('warga.ajukan');
        Route::post('ajukan', [WargaController::class, 'kirim'])
            ->middleware('throttle:20,60')   // pengajuan daring, bukan loket
            ->name('warga.ajukan.kirim');

        Route::get('pengajuan', [WargaController::class, 'pengajuan'])->name('warga.pengajuan');
        Route::get('pengajuan/{id}', [WargaController::class, 'pengajuanDetail'])
            ->name('warga.pengajuan.detail');

        /*
         * "Pengaduan saya" — FR-PGD-02.
         *
         * Hanya pengaduan yang ia laporkan SENDIRI, bukan satu KK seperti
         * daftar pengajuan surat di atasnya. Pengaduan anonim tidak muncul di
         * sini sama sekali, karena RULE-09 melarang menyimpan tautan ke
         * akunnya; layarnya WAJIB mengatakan itu agar warga tidak menyangka
         * laporannya hilang. Jalurnya `pengaduan/lacak` dengan kode.
         */
        Route::get('pengaduan', [WargaController::class, 'pengaduan'])->name('warga.pengaduan');
        Route::get('pengaduan/{id}', [WargaController::class, 'pengaduanDetail'])
            ->name('warga.pengaduan.detail');
    });

    /* ------------------- Pengaduan: panel petugas (Sprint 7) ------------
     | UC-14, FR-PGD-03 s.d. FR-PGD-07.
     |
     | TIGA izin yang berbeda, mengikuti matriks RBAC docs/03 §4.9:
     |   - `pengaduan.lihat` membuka antrean dan detail. Kepala Desa ("R") dan
     |     Kepala Dusun ("R(wilayah)") berhenti di sini — keduanya membaca,
     |     tidak menindaklanjuti.
     |   - `pengaduan.tindaklanjut` mengubah status dan mencatat tindak lanjut.
     |   - `pengaduan.tugaskan` melimpahkan ke petugas lain.
     |
     | Apa yang DILIHAT masing-masing tetap dibatasi ComplaintRepository, bukan
     | oleh rute: Kepala Dusun yang membuka pengaduan dusun lain mendapat 404,
     | bukan 403 (docs/06 §4).
     */
    /*
     | DI BAWAH `pengaduan/antrean`, BUKAN `pengaduan` saja — dan ini bukan
     | selera penamaan melainkan tabrakan yang sudah terjadi sekali.
     |
     | `GET /pengaduan` sudah dipakai FORMULIR PUBLIK di atas. Laravel
     | memetakan rute berdasarkan pasangan metode+URI, sehingga pendaftaran
     | yang belakangan MENGGANTI yang terdahulu tanpa satu pun peringatan:
     | antrean petugas sempat menelan formulir pengaduan warga, dan yang
     | tersisa bagi warga adalah halaman yang menuntut login.
     |
     | Yang pendek diberikan kepada WARGA, bukan kepada petugas. Warga
     | mengetik atau membaca `/pengaduan` dari poster dan dari halaman portal;
     | petugas sampai ke antreannya lewat menu, sehingga panjang URL-nya tidak
     | menjadi beban siapa pun.
     |
     | Prefiks ini sekaligus mengeluarkan `{id}` dari ruang nama `/pengaduan/*`,
     | yang di sana sudah ada `/pengaduan/lacak` — tanpa itu, keduanya hanya
     | dipisahkan oleh urutan pendaftaran.
     */
    Route::prefix('pengaduan/antrean')->group(function (): void {
        Route::get('/', [ComplaintController::class, 'index'])
            ->middleware('izin:pengaduan.lihat')->name('pengaduan.index');
        Route::get('{id}', [ComplaintController::class, 'show'])
            ->middleware('izin:pengaduan.lihat')->name('pengaduan.detail');
        Route::post('{id}/status', [ComplaintController::class, 'transition'])
            ->middleware('izin:pengaduan.tindaklanjut')->name('pengaduan.status');
        Route::post('{id}/tindak-lanjut', [ComplaintController::class, 'followUp'])
            ->middleware('izin:pengaduan.tindaklanjut')->name('pengaduan.tindak-lanjut');
        Route::post('{id}/terbitkan', [ComplaintController::class, 'publish'])
            ->middleware('izin:pengaduan.tindaklanjut')->name('pengaduan.terbitkan');
        Route::post('{id}/tugaskan', [ComplaintController::class, 'assign'])
            ->middleware('izin:pengaduan.tugaskan')->name('pengaduan.tugaskan');
    });

    /* ------------------- Laporan & ekspor (Sprint 7) --------------------
     | FR-LAP-03 s.d. FR-LAP-07, UC-18.
     |
     | DUA tingkat izin, mengikuti matriks RBAC docs/03 §4.9 baris
     | "Laporan & ekspor" (R,X):
     |   - `laporan.lihat` membuka halaman dan melihat angkanya.
     |   - `laporan.ekspor` mengunduhnya. Setiap unduhan tercatat di audit log
     |     beserta jumlah baris (BR-41, SRS §4.9 butir 3).
     |
     | Buku administrasi menuntut `penduduk.ekspor` DI ATAS `laporan.lihat`,
     | dan itu bukan kelebihan kehati-hatian: yang keluar dari sana bukan
     | rekap melainkan satu baris per warga beserta nama, tanggal lahir, dan
     | alamatnya — ekspor data penduduk dalam bentuk lain, jadi izinnya harus
     | izin yang sama.
     |
     | Apa yang MASUK ke dalam laporan tetap dibatasi repository berlingkup:
     | Kepala Dusun mencetak rekap dusunnya, dan halamannya mengatakan itu.
     */
    Route::prefix('laporan')->group(function (): void {
        Route::get('/', [ReportController::class, 'index'])
            ->middleware('izin:laporan.lihat')->name('laporan.index');

        Route::middleware('izin:laporan.ekspor')->group(function (): void {
            Route::get('penduduk.csv', [ReportController::class, 'exportPopulation'])
                ->name('laporan.penduduk.csv');
            Route::get('mutasi.csv', [ReportController::class, 'exportMutations'])
                ->name('laporan.mutasi.csv');
            Route::get('layanan.csv', [ReportController::class, 'exportServices'])
                ->name('laporan.layanan.csv');
            Route::get('pengaduan.csv', [ReportController::class, 'exportComplaints'])
                ->name('laporan.pengaduan.csv');
        });

        Route::middleware('izin:penduduk.ekspor')->group(function (): void {
            Route::get('buku-induk.pdf', [ReportController::class, 'residentRegister'])
                ->name('laporan.buku-induk');
            Route::get('buku-mutasi.pdf', [ReportController::class, 'mutationRegister'])
                ->name('laporan.buku-mutasi');
        });
    });

    /* ---------------- Verifikasi akun warga (Sprint 6) -----------------
     | FR-AUT-09, UC-15. Izin `pengguna.verifikasi` — pada matriks RBAC
     | hanya Admin Desa: menyetujui satu akun warga sama beratnya dengan
     | mengubah data kependudukan, bukan pekerjaan loket.
     */
    Route::prefix('pengaturan/verifikasi-warga')
        ->middleware('izin:pengguna.verifikasi')
        ->group(function (): void {
            Route::get('/', [CitizenVerificationController::class, 'index'])
                ->name('verifikasi-warga.index');
            Route::get('{id}/dokumen', [CitizenVerificationController::class, 'document'])
                ->name('verifikasi-warga.dokumen');
            Route::post('{id}/setujui', [CitizenVerificationController::class, 'approve'])
                ->name('verifikasi-warga.setujui');
            Route::post('{id}/tolak', [CitizenVerificationController::class, 'reject'])
                ->name('verifikasi-warga.tolak');
        });

    /* ------------------- Konten portal (Sprint 6) ----------------------
     | Tiga izin berbeda, sesuai matriks RBAC docs/03 §4.9 baris "Konten
     | portal": `portal.lihat` (Kepala Desa & Pengawas berhenti di sini),
     | `portal.kelola` (menyunting), dan `portal.terbitkan` yang TIDAK
     | dipasang sebagai middleware rute — rutenya sama untuk menyimpan draf
     | dan menerbitkan, jadi pemeriksaannya ada di PostController.
     */
    Route::prefix('portal/konten')->group(function (): void {
        Route::get('/', [PostController::class, 'index'])
            ->middleware('izin:portal.lihat')->name('portal-konten.index');
        Route::get('tambah', [PostController::class, 'create'])
            ->middleware('izin:portal.kelola')->name('portal-konten.create');
        Route::post('/', [PostController::class, 'store'])
            ->middleware('izin:portal.kelola')->name('portal-konten.store');
        Route::get('{id}/ubah', [PostController::class, 'edit'])
            ->middleware('izin:portal.kelola')->name('portal-konten.edit');
        Route::post('{id}', [PostController::class, 'update'])
            ->middleware('izin:portal.kelola')->name('portal-konten.update');
        Route::delete('{id}', [PostController::class, 'destroy'])
            ->middleware('izin:portal.kelola')->name('portal-konten.destroy');
    });

    Route::prefix('jenis-surat')->group(function (): void {
        Route::get('/', [LetterTypeController::class, 'index'])
            ->middleware('izin:jenis_surat.lihat')->name('jenis-surat.index');
        Route::post('pratinjau', [LetterTypeController::class, 'preview'])
            ->middleware('izin:jenis_surat.kelola')->name('jenis-surat.preview');
        Route::get('{id}/ubah', [LetterTypeController::class, 'edit'])
            ->middleware('izin:jenis_surat.kelola')->name('jenis-surat.edit');
        Route::put('{id}', [LetterTypeController::class, 'update'])
            ->middleware('izin:jenis_surat.kelola')->name('jenis-surat.update');
        Route::post('{id}/aktif', [LetterTypeController::class, 'toggle'])
            ->middleware('izin:jenis_surat.kelola')->name('jenis-surat.toggle');
    });

    Route::get('pengaturan/log-aktivitas', fn () => Inertia::render('Placeholder', [
        'title' => 'Log Aktivitas',
        'sprint' => 'Sprint 7',
    ]))->middleware('izin:audit.lihat')->name('audit.index');
});
