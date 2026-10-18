<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\LetterRequest;
use Illuminate\Notifications\Notification;

/**
 * Pengajuan baru menunggu pemeriksaan, dikirim ke PETUGAS — UC-05 hasil akhir.
 *
 * Hanya untuk pengajuan DARING. Pengajuan loket dibuat petugas sendiri yang
 * sedang berdiri di depan warganya; memberitahunya tentang pekerjaan yang
 * baru saja ia buat hanya melatih orang mengabaikan notifikasi.
 */
final class LetterRequestAwaitingAction extends Notification
{
    public function __construct(private readonly LetterRequest $request) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kategori' => 'surat.antrean',
            'judul' => 'Pengajuan daring baru: '.$this->request->letterType->name,
            'isi' => 'Tiket '.$this->request->ticket_number
                .' diajukan warga lewat portal dan menunggu pemeriksaan Anda.',
            'tiket' => $this->request->ticket_number,
            'jenis_surat' => $this->request->letterType->name,
            'status' => $this->request->status,
            'tautan' => '/surat/'.$this->request->id,
        ];
    }
}
