<?php

declare(strict_types=1);

namespace App\Http\Controllers\Letter;

use App\Http\Controllers\Controller;
use App\Http\Resources\LetterRequestPresenter;
use App\Repositories\LetterRequestRepository;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pelacakan status pengajuan surat oleh warga — FR-SRT-13, BR-11.
 *
 * ENDPOINT PUBLIK, TANPA OTENTIKASI, TETAPI BERFAKTOR DUA.
 *
 * KEPUTUSAN DAN ALASANNYA (KEP-014):
 *
 * Nomor tiket berbentuk `T261015-C507E9`: tanggal pengajuan ditambah enam
 * karakter acak. Ia DAPAT dicoba-coba — tidak sebanding dengan kode
 * verifikasi surat yang 32 karakter acak dan karenanya boleh berdiri sendiri
 * sebagai pembuka halaman publik (LetterVerificationController). Halaman yang
 * membuka riwayat pengajuan hanya dari nomor tiket berarti siapa pun yang mau
 * menghabiskan beberapa ribu permintaan dapat memetakan siapa mengajukan
 * surat apa di desa ini — termasuk SKTM, yang isinya keadaan ekonomi keluarga.
 *
 * Dua pilihan dipertimbangkan:
 *
 *   (a) Mewajibkan login. Paling ketat, tetapi akun warga baru ada di
 *       Sprint 6 (registrasi + verifikasi akun, KEP-013 §2). Hari ini hampir
 *       seluruh pengajuan dibuat di loket atas nama warga yang tidak punya
 *       akun, sehingga BR-11 akan menjadi fitur yang tidak dapat dipakai
 *       siapa pun sampai sprint berikutnya.
 *   (b) Faktor kedua yang hanya diketahui pemohon: TANGGAL LAHIR PEMOHON.
 *
 * Dipilih (b). Alasannya: pemohon selalu mengetahui tanggal lahirnya,
 * petugas loket sudah mencetak nomor tiket untuknya, dan pasangan
 * tiket + tanggal lahir mempersempit ruang terkaan dari ~17 juta menjadi
 * ruang itu dikali seluruh tanggal lahir yang mungkin. Tanggal lahir BUKAN
 * rahasia kuat; karena itu ia tidak berdiri sendiri — ia hanya berlaku
 * bersama tiket yang tepat, dan rutenya dibatasi laju lebih ketat daripada
 * rute verifikasi surat (lihat routes/web.php).
 *
 * Saat akun warga tersedia di Sprint 6, halaman ini tetap berguna sebagai
 * "cek resi" tanpa login; daftar pengajuan milik akun sendiri menjadi jalur
 * kedua, bukan pengganti.
 *
 * YANG TIDAK PERNAH DIKIRIM KE PERAMBAN: NIK (bahkan yang tersamar), nomor
 * KK, alamat, isi formulir, dan nama petugas pemeriksa. Yang dikirim hanyalah
 * status sekarang, estimasi selesai, dan riwayat waktu tiap tahap beserta
 * alasan penolakan / catatan revisi — persis yang diminta BR-11.
 *
 * Kegagalan TIDAK membedakan "tiket tidak ada" dari "tanggal lahir salah":
 * jawaban yang berbeda akan mengubah halaman ini menjadi alat untuk
 * memastikan sebuah tiket memang ada.
 *
 * Tanpa konstruktor (KEP-009).
 */
final class LetterTrackingController extends Controller
{
    /** Formulir kosong. */
    public function show(): Response
    {
        return Inertia::render('Publik/LacakSurat', [
            'submitted' => false,
            'found' => false,
        ]);
    }

    public function track(
        Request $request,
        LetterRequestRepository $requests,
        AuditLogger $audit,
    ): Response {
        $validated = $request->validate([
            'ticket_number' => ['required', 'string', 'max:40'],
            // `date_format` dan bukan `date`: peramban mengirim input[type=date]
            // sebagai Y-m-d, dan `date` juga meluluskan "besok" atau "17 Mar"
            // yang kemudian menjadi kueri tanggal yang bukan tanggal.
            'birth_date' => ['required', 'date_format:Y-m-d'],
        ], [
            'ticket_number.required' => 'Nomor tiket wajib diisi.',
            'birth_date.required' => 'Tanggal lahir pemohon wajib diisi.',
            'birth_date.date_format' => 'Tanggal lahir tidak dikenali.',
        ]);

        $ticket = Str::upper(trim($validated['ticket_number']));

        $letterRequest = $requests->findByTicketForTracking($ticket, $validated['birth_date']);

        if ($letterRequest === null) {
            /*
             * Percobaan gagal TETAP dicatat, sama seperti verifikasi surat.
             *
             * Rentetan kegagalan dari satu alamat IP adalah satu-satunya
             * tanda yang terlihat bahwa seseorang sedang menebak nomor
             * tiket; pembatasan laju memperlambatnya, tetapi tidak
             * memberitahu siapa pun bahwa itu terjadi.
             *
             * Tanggal lahir yang dicoba TIDAK ikut dicatat: ia data pribadi
             * dan audit log tidak boleh menjadi tempat ia menumpuk.
             */
            $audit->log('letter.tracking_failed', context: ['tiket' => Str::limit($ticket, 40)]);

            return Inertia::render('Publik/LacakSurat', [
                'submitted' => true,
                'found' => false,
                'ticket_number' => $ticket,
            ]);
        }

        $audit->log('letter.tracked', $letterRequest, context: ['tiket' => $ticket]);

        return Inertia::render('Publik/LacakSurat', [
            'submitted' => true,
            'found' => true,
            'ticket_number' => $ticket,
            'tracking' => LetterRequestPresenter::tracking($letterRequest),
        ]);
    }
}
