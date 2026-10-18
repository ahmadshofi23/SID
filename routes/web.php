<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Letter\LetterAttachmentController;
use App\Http\Controllers\Letter\LetterController;
use App\Http\Controllers\Letter\LetterPdfController;
use App\Http\Controllers\Letter\LetterRequestController;
use App\Http\Controllers\Letter\LetterTrackingController;
use App\Http\Controllers\Letter\LetterTypeController;
use App\Http\Controllers\Letter\LetterVerificationController;
use App\Http\Controllers\Letter\MutationOfferController;
use App\Http\Controllers\Population\AnomalyController;
use App\Http\Controllers\Population\FamilyController;
use App\Http\Controllers\Population\ImportController;
use App\Http\Controllers\Population\MutationController;
use App\Http\Controllers\Population\RegionController;
use App\Http\Controllers\Population\ResidentController;
use App\Http\Controllers\Population\StatisticsController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Rute Web
|--------------------------------------------------------------------------
| URL berbahasa Indonesia secara sengaja: perangkat desa dan warga melihat
| dan kadang mengetikkan URL ini (NFR-USA-01).
*/

Route::view('/', 'welcome')->name('home');

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
