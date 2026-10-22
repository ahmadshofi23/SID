<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Complaint;
use App\Support\VillageTime;
use Illuminate\Notifications\Notification;

/**
 * Pengaduan baru masuk, dikirim ke PETUGAS yang dapat mentriasenya —
 * FR-PGD-03, FR-PGD-06.
 *
 * Padanan LetterRequestAwaitingAction pada persuratan, dan ada karena alasan
 * yang sama: antrean yang hanya terlihat bila seseorang membuka halamannya
 * akan menumpuk sampai SLA terlampaui.
 */
final class ComplaintAwaitingTriage extends Notification
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
        return [
            'kategori' => 'pengaduan.baru',
            'judul' => 'Pengaduan baru menunggu triase',
            'isi' => $this->complaint->title.' — batas triase '
                .$this->triageLabel().'.',
            'tiket' => $this->complaint->ticket_number,
            'status' => $this->complaint->status,
            'tautan' => '/pengaduan/'.$this->complaint->id,
        ];
    }

    private function triageLabel(): string
    {
        $due = $this->complaint->triage_due_at;

        return $due === null
            ? 'belum ditetapkan'
            : VillageTime::local($due)->locale('id')->translatedFormat('j F Y');
    }
}
