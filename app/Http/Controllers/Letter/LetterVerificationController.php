<?php

declare(strict_types=1);

namespace App\Http\Controllers\Letter;

use App\Http\Controllers\Controller;
use App\Models\Letter;
use App\Repositories\LetterRepository;
use App\Services\Audit\AuditLogger;
use App\Support\NameMask;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Verifikasi keaslian surat dari kode QR — FR-SRT-14.
 *
 * ENDPOINT PUBLIK, TANPA OTENTIKASI. Dipakai bank, sekolah, dan kantor
 * kecamatan yang menerima surat dari warga dan ingin memastikan surat itu
 * benar diterbitkan desa.
 *
 * APA YANG DITAMPILKAN DI SINI DIBATASI KETAT: nomor surat, jenis, nama
 * pemohon yang DISAMARKAN, tanggal terbit, masa berlaku, dan status.
 *
 * YANG TIDAK PERNAH DITAMPILKAN: NIK, nomor KK, alamat, isi surat, maupun
 * snapshot. Siapa pun yang memegang selembar surat dapat membuka halaman ini
 * — ia harus dapat memastikan keaslian TANPA memperoleh data tambahan apa pun
 * tentang warga tersebut.
 *
 * Tanpa konstruktor (KEP-009).
 */
final class LetterVerificationController extends Controller
{
    public function __invoke(
        string $code,
        LetterRepository $letters,
        AuditLogger $audit,
    ): Response {
        $letter = $letters->findByVerificationCode(Str::upper(trim($code)));

        if ($letter === null) {
            /*
             * Pemeriksaan gagal TETAP dicatat.
             *
             * Rentetan kode gagal dari satu alamat IP adalah tanda seseorang
             * sedang menebak kode verifikasi, dan itu hanya terlihat bila
             * kegagalannya tercatat.
             */
            $audit->log('letter.verification_failed', context: ['kode' => Str::limit($code, 40)]);

            return Inertia::render('Publik/VerifikasiSurat', [
                'found' => false,
                'code' => $code,
            ]);
        }

        $audit->log('letter.verified', $letter, context: [
            'nomor_surat' => $letter->letter_number,
        ]);

        return Inertia::render('Publik/VerifikasiSurat', [
            'found' => true,
            'code' => $letter->verification_code,
            'letter' => [
                'letter_number' => $letter->letter_number,
                'letter_type' => $letter->letterType->name,
                // Nama disamarkan: cukup untuk mencocokkan dengan surat di
                // tangan, tidak cukup untuk menjadi sumber data warga.
                'resident_name' => NameMask::partial($letter->request->resident->full_name),
                'issued_at' => $letter->issued_at->locale('id')->translatedFormat('d F Y'),
                'valid_until' => $letter->valid_until?->locale('id')->translatedFormat('d F Y'),
                'is_expired' => $letter->isExpired(),
                'status' => $letter->status,
                'status_label' => $letter->status === Letter::STATUS_CANCELLED
                    ? 'DIBATALKAN'
                    : ($letter->isExpired() ? 'SAH (MASA BERLAKU HABIS)' : 'SAH'),
                'signer_position' => $letter->signer_position,
            ],
        ]);
    }
}
