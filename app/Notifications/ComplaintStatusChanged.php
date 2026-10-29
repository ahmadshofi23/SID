<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Complaint;
use App\Support\VillageTime;
use Illuminate\Notifications\Notification;

/**
 * Perubahan status atau tindak lanjut pengaduan, dikirim ke PELAPOR —
 * FR-PGD-03, FR-PGD-05.
 *
 * Hanya pelapor yang punya akun dan tidak anonim yang menerimanya; penentuan
 * itu ada di ComplaintNotifier, bukan di sini.
 *
 * Kanal hari ini hanya `database` (in-app), sama seperti notifikasi surat.
 * Fase 2 menambahkan `mail` dan WhatsApp di sini — bukan menulis ulang
 * pemanggilnya.
 */
final class ComplaintStatusChanged extends Notification
{
    public function __construct(
        private readonly Complaint $complaint,
        private readonly string $fromStatus,
        private readonly ?string $followUpNote = null,
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
            'kategori' => 'pengaduan.status',
            'judul' => $this->title(),
            'isi' => $this->body(),
            'tiket' => $this->complaint->ticket_number,
            'status' => $this->complaint->status,
            'status_lama' => $this->fromStatus,
            /*
             * Tautan DALAM aplikasi, bukan URL penuh — alasannya sama dengan
             * notifikasi surat: URL penuh yang tersimpan di basis data akan
             * salah begitu desa berpindah domain.
             *
             * Dan yang TIDAK pernah ikut ke sini: `tracking_code`. Notifikasi
             * tersimpan sebagai baris basis data yang dibaca banyak jalur;
             * kode pelacakan adalah rahasia pembuka pengaduan, dan rahasia
             * yang disalin ke banyak tempat berhenti menjadi rahasia.
             */
            'tautan' => '/warga/pengaduan/'.$this->complaint->id,
        ];
    }

    private function title(): string
    {
        if ($this->followUpNote !== null) {
            return 'Ada tindak lanjut baru atas pengaduan Anda';
        }

        return match ($this->complaint->status) {
            Complaint::STATUS_ACCEPTED => 'Pengaduan Anda diterima',
            Complaint::STATUS_IN_PROGRESS => 'Pengaduan Anda sedang ditangani',
            Complaint::STATUS_DONE => 'Pengaduan Anda selesai ditangani',
            Complaint::STATUS_REJECTED => 'Pengaduan Anda tidak dapat ditindaklanjuti',
            default => 'Status pengaduan Anda berubah',
        };
    }

    /**
     * Isi notifikasi — ditulis untuk warga, bukan untuk petugas.
     *
     * Setiap kalimat menjawab satu pertanyaan: apa yang terjadi, dan apa yang
     * harus saya lakukan sekarang. Notifikasi yang hanya menyebut nama status
     * memaksa warga menelepon kantor desa untuk menanyakan artinya — persis
     * beban yang hendak dihapus BR-30.
     */
    private function body(): string
    {
        if ($this->followUpNote !== null) {
            return $this->followUpNote;
        }

        $tiket = $this->complaint->ticket_number;

        return match ($this->complaint->status) {
            Complaint::STATUS_ACCEPTED => 'Pengaduan '.$tiket.' sudah diperiksa dan '
                .'diterima sebagai urusan desa. Perkiraan selesai '
                .$this->dueLabel().'. Belum ada yang perlu Anda lakukan.',

            Complaint::STATUS_IN_PROGRESS => 'Petugas sudah mulai menangani pengaduan '
                .$tiket.'. Anda akan diberi tahu lagi ketika ada perkembangan.',

            Complaint::STATUS_DONE => 'Hasil penanganan: '
                .($this->complaint->resolution ?? 'lihat halaman pengaduan.')
                .' Bila menurut Anda persoalannya belum selesai, sampaikan pengaduan '
                .'baru dengan menyebut nomor '.$tiket.'.',

            Complaint::STATUS_REJECTED => 'Alasan: '
                .($this->complaint->resolution ?? 'lihat halaman pengaduan.'),

            default => 'Status pengaduan '.$tiket.' berubah.',
        };
    }

    private function dueLabel(): string
    {
        $due = $this->complaint->due_at;

        return $due === null
            ? 'menyusul'
            : VillageTime::local($due)->locale('id')->translatedFormat('j F Y');
    }
}
