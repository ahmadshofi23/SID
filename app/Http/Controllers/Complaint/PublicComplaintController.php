<?php

declare(strict_types=1);

namespace App\Http\Controllers\Complaint;

use App\Auth\AccessScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Complaint\StoreComplaintRequest;
use App\Http\Resources\ComplaintPresenter;
use App\Repositories\ComplaintRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Complaint\ComplaintService;
use App\Services\Population\RegionService;
use App\Support\ReferenceData;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pengaduan yang menghadap warga — FR-PGD-01, FR-PGD-02, UC-13.
 *
 * MENGAPA INERTIA DAN BUKAN BLADE, padahal KEP-016 mewajibkan halaman portal
 * BARU dibangun dengan Blade: KEP-016 §5 menetapkan batasnya pada FORMULIR
 * DENGAN KEADAAN VALIDASI — verifikasi keaslian surat dan pelacakan status
 * tetap Inertia karena itu. Formulir pengaduan ada di sisi yang sama dan
 * bahkan lebih jauh: ia punya pilihan anonim yang mengubah isi formulir saat
 * dicentang, pilihan wilayah bertingkat, dan tanda terima yang muncul
 * setelah kirim. Halaman yang menampilkan PROFIL dan BERITA desa tetap Blade;
 * yang satu dituju mesin pencari dan harus hidup tanpa JavaScript, yang satu
 * ini adalah transaksi.
 *
 * TANPA OTENTIKASI, dan itu keputusan yang disengaja, bukan kelalaian:
 * matriks RBAC docs/03 §4.9 memberi PB (publik) "C(anonim)" atas pengaduan.
 * Hak mengadu tidak boleh bergantung pada punya tidaknya akun — orang yang
 * paling perlu mengadu seringkali orang yang paling tidak terdaftar di mana
 * pun. Yang membatasi penyalahgunaannya adalah pembatasan laju pada rutenya
 * dan hitungan per IP di ComplaintService, bukan tembok login.
 *
 * Tanpa konstruktor (KEP-009).
 */
final class PublicComplaintController extends Controller
{
    /** Formulir pengaduan. */
    public function create(Request $request, RegionService $regions): Response
    {
        $user = $request->user();

        return Inertia::render('Publik/Pengaduan', [
            'kategori' => ReferenceData::get('complaint_categories'),
            'wilayah' => $regions->rtOptions(),
            /*
             * Nama & telepon pelapor diisi awal HANYA untuk yang sudah masuk.
             *
             * Dan hanya sebagai nilai awal yang dapat ia hapus — bukan kolom
             * terkunci: warga yang masuk sebagai akunnya sendiri tetap boleh
             * memilih melapor anonim, dan formulir yang memaksa namanya
             * terpasang berarti janji anonim pada FR-PGD-02 hanya berlaku bagi
             * orang yang belum mendaftar.
             */
            'pelapor' => $user === null ? null : [
                'name' => $user->name,
                'phone' => $user->phone,
            ],
        ]);
    }

    public function store(
        StoreComplaintRequest $request,
        ComplaintService $service,
        AccessScope $scope,
    ): Response {
        $complaint = $service->create($scope, $request->validated(), $request->ip());

        /*
         * Tanda terima dirender LANGSUNG, bukan lewat redirect.
         *
         * Kode pelacakan hanya muncul sekali dan tidak dapat dibuka kembali.
         * Melewatkannya lewat flash session berarti ia ikut tersimpan di
         * berkas sesi di server dan dapat terbawa ke halaman lain bila warga
         * menekan muat ulang; dirender langsung, ia hanya ada di satu balasan
         * HTTP dan tidak di mana pun lagi.
         */
        return Inertia::render('Publik/PengaduanTerkirim', [
            'tanda_terima' => ComplaintPresenter::receipt($complaint, $complaint->tracking_code),
        ]);
    }

    /** Formulir pelacakan kosong. */
    public function showTracking(): Response
    {
        return Inertia::render('Publik/LacakPengaduan', [
            'submitted' => false,
            'found' => false,
        ]);
    }

    /**
     * Pelacakan dengan kode — FR-PGD-02.
     *
     * SATU FAKTOR, berbeda dari pelacakan surat yang menuntut tiket + tanggal
     * lahir (KEP-014). Alasannya: tidak ada faktor kedua yang dapat diminta
     * dari pelapor anonim tanpa menghapus keanoniman itu sendiri — tanggal
     * lahir adalah identitas. Yang menggantikannya adalah panjang kodenya
     * (16 karakter acak, bukan nomor bertanggal yang beredar di berkas desa)
     * dan pembatasan laju pada rutenya.
     */
    public function track(
        Request $request,
        ComplaintRepository $complaints,
        AuditLogger $audit,
    ): Response {
        $validated = $request->validate([
            'tracking_code' => ['required', 'string', 'max:20'],
        ], [
            'tracking_code.required' => 'Kode pelacakan wajib diisi.',
        ]);

        $code = Str::upper(trim($validated['tracking_code']));

        $complaint = $complaints->findByTrackingCode($code);

        if ($complaint === null) {
            /*
             * Percobaan gagal dicatat, TETAPI KODENYA TIDAK.
             *
             * Berbeda dari pelacakan surat, yang mencatat nomor tiket yang
             * dicoba: nomor tiket adalah pengenal administratif, sedangkan
             * kode pelacakan adalah RAHASIA. Kode yang salah ketik satu huruf
             * dan tersimpan di audit log adalah rahasia yang bocor ke tempat
             * yang dibaca Admin Desa — pihak yang paling sering diadukan.
             * Yang dicatat hanyalah bahwa ada percobaan gagal, karena
             * rentetannya adalah satu-satunya tanda terlihat bahwa seseorang
             * sedang menebak.
             */
            $audit->log('complaint.tracking_failed');

            return Inertia::render('Publik/LacakPengaduan', [
                'submitted' => true,
                'found' => false,
            ]);
        }

        /*
         * Keberhasilan TIDAK dicatat dengan menyebut pengaduannya.
         *
         * AuditLogger::log() dengan model akan menyimpan id pengaduan beserta
         * identitas pembukanya bila ia sedang masuk — yang pada pengaduan
         * anonim berarti audit log menyimpan tautan antara sebuah akun dan
         * sebuah pengaduan anonim. Itu persis yang dilarang RULE-09, lewat
         * pintu belakang.
         */
        return Inertia::render('Publik/LacakPengaduan', [
            'submitted' => true,
            'found' => true,
            'pengaduan' => ComplaintPresenter::tracking($complaint),
        ]);
    }
}
