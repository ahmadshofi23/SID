<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Complaint;
use Illuminate\Notifications\Notification;

/**
 * Eskalasi SLA pengaduan — FR-PGD-06.
 *
 * Dikirim ketika pengaduan melewati batas triase atau batas penyelesaiannya.
 * Nada pesannya sengaja menyebut BERAPA LAMA terlambat, bukan hanya bahwa ia
 * terlambat: "terlambat 11 hari" membuat seseorang bertindak hari ini,
 * sedangkan "melewati SLA" dapat dibaca setiap hari tanpa pernah menggerakkan
 * apa pun.
 */
final class ComplaintEscalated extends Notification
{
    public function __construct(private readonly Complaint $complaint) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $tahap = $this->complaint->status === Complaint::STATUS_NEW
            ? 'belum ditriase'
            : 'belum selesai';

        return [
            'kategori' => 'pengaduan.eskalasi',
            'judul' => 'Pengaduan terlambat: '.$this->complaint->ticket_number,
            'isi' => $this->complaint->title.' — '.$tahap.', terlambat '
                .$this->lateLabel().'. Prioritasnya dinaikkan menjadi tinggi.',
            'tiket' => $this->complaint->ticket_number,
            'status' => $this->complaint->status,
            'tautan' => '/pengaduan/antrean/'.$this->complaint->id,
        ];
    }

    private function lateLabel(): string
    {
        $due = $this->complaint->activeDueAt();

        if ($due === null) {
            return 'tidak diketahui';
        }

        $days = (int) $due->diffInDays(now());

        return $days < 1 ? 'kurang dari sehari' : $days.' hari';
    }
}
