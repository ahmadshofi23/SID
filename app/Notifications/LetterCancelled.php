<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Letter;
use Illuminate\Notifications\Notification;

/**
 * Surat terbit DIBATALKAN — RULE-05, FR-SRT-12.
 *
 * Kelas tersendiri, bukan varian LetterRequestStatusChanged, karena status
 * PENGAJUAN-nya tidak berubah sama sekali: ia tetap `diterbitkan`. Yang
 * berubah adalah keabsahan suratnya, dan itu kabar yang sangat berbeda bagi
 * warga — surat yang sudah ia pegang dan mungkin sudah ia serahkan ke bank
 * atau sekolah kini tidak lagi berlaku bila diperiksa lewat QR.
 */
final class LetterCancelled extends Notification
{
    public function __construct(
        private readonly Letter $letter,
        private readonly string $reason,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kategori' => 'surat.dibatalkan',
            'judul' => 'Surat nomor '.$this->letter->letter_number.' dibatalkan',
            'isi' => 'Alasan: '.$this->reason
                .'. Surat yang sudah Anda terima tidak lagi berlaku bila diperiksa '
                .'lewat kode QR-nya. Hubungi kantor desa untuk penggantinya.',
            'nomor_surat' => $this->letter->letter_number,
            'tautan' => '/warga/pengajuan/'.$this->letter->request_id,
        ];
    }
}
