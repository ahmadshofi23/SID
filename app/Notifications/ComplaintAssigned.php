<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Complaint;
use App\Support\VillageTime;
use Illuminate\Notifications\Notification;

/**
 * Penugasan pengaduan, dikirim ke PENERIMA TUGAS — FR-PGD-04.
 */
final class ComplaintAssigned extends Notification
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
            'kategori' => 'pengaduan.tugas',
            'judul' => 'Anda ditugaskan menangani satu pengaduan',
            /*
             * JUDUL pengaduan ikut disebut, berbeda dari audit log yang
             * sengaja tidak memuatnya. Perbedaannya pada pembacanya:
             * notifikasi ini hanya dibaca orang yang memang ditugaskan
             * menanganinya, dan tanpa judulnya ia harus membuka setiap
             * notifikasi hanya untuk tahu mana yang mendesak.
             */
            'isi' => $this->complaint->title.' — batas penyelesaian '
                .$this->dueLabel().'.',
            'tiket' => $this->complaint->ticket_number,
            'status' => $this->complaint->status,
            'tautan' => '/pengaduan/antrean/'.$this->complaint->id,
        ];
    }

    private function dueLabel(): string
    {
        $due = $this->complaint->activeDueAt();

        return $due === null
            ? 'belum ditetapkan'
            : VillageTime::local($due)->locale('id')->translatedFormat('j F Y');
    }
}
