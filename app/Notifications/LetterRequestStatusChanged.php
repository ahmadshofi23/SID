<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\LetterRequest;
use App\Support\VillageTime;
use Illuminate\Notifications\Notification;

/**
 * Perubahan status pengajuan, dikirim ke PEMOHON — FR-SRT-14, BR-11.
 *
 * SATU-SATUNYA TEMPAT bentuk isi `data` ditentukan (lihat keterangan pada
 * migrasi tabel notifications). Tidak ada kode lain yang menyusunnya sendiri.
 *
 * Kanal hari ini hanya `database` (in-app). Fase 2 menambahkan `mail` dan
 * WhatsApp di sini — bukan menulis ulang pemanggilnya.
 */
final class LetterRequestStatusChanged extends Notification
{
    public function __construct(
        private readonly LetterRequest $request,
        private readonly string $fromStatus,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $type = $this->request->letterType;

        return [
            'kategori' => 'surat.status',
            'judul' => $this->title(),
            'isi' => $this->body(),
            'tiket' => $this->request->ticket_number,
            'jenis_surat' => $type->name,
            'status' => $this->request->status,
            'status_lama' => $this->fromStatus,
            /*
             * Tautan DALAM aplikasi, bukan URL penuh: notifikasi dibaca di
             * peramban yang sudah berada di domain desa, dan URL penuh yang
             * tersimpan di basis data akan salah begitu desa berpindah domain
             * atau dibuka lewat nama lain di jaringan setempat.
             */
            'tautan' => '/warga/pengajuan/'.$this->request->id,
        ];
    }

    private function title(): string
    {
        $nama = $this->request->letterType->name;

        return match ($this->request->status) {
            LetterRequest::STATUS_SUBMITTED => $nama.' sudah masuk antrean',
            LetterRequest::STATUS_VERIFIED => $nama.' lolos pemeriksaan',
            LetterRequest::STATUS_NEEDS_REVISION => $nama.' perlu diperbaiki',
            LetterRequest::STATUS_ISSUED => $nama.' sudah terbit',
            LetterRequest::STATUS_REJECTED => $nama.' ditolak',
            LetterRequest::STATUS_CANCELLED => $nama.' dibatalkan',
            default => 'Status '.$nama.' berubah',
        };
    }

    /**
     * Isi notifikasi — ditulis untuk warga, bukan untuk petugas.
     *
     * Setiap kalimat menjawab satu pertanyaan: apa yang terjadi, dan apa yang
     * harus saya lakukan sekarang. Notifikasi yang hanya menyebut nama status
     * ("Status menjadi DIVERIFIKASI") memaksa warga menelepon kantor desa
     * untuk menanyakan artinya — persis beban yang hendak dihapus BR-11.
     */
    private function body(): string
    {
        $tiket = $this->request->ticket_number;

        return match ($this->request->status) {
            LetterRequest::STATUS_SUBMITTED => 'Pengajuan Anda tercatat dengan nomor tiket '
                .$tiket.'. Perkiraan selesai '
                .($this->request->due_at === null
                    ? 'menyusul'
                    : VillageTime::local($this->request->due_at)->locale('id')->translatedFormat('j F Y'))
                .'.',

            LetterRequest::STATUS_VERIFIED => 'Berkas Anda sudah diperiksa petugas dan '
                .'menunggu persetujuan pimpinan desa. Belum ada yang perlu Anda lakukan.',

            LetterRequest::STATUS_NEEDS_REVISION => 'Petugas meminta perbaikan: '
                .($this->request->revision_note ?? 'lihat catatan pada halaman pengajuan.')
                .' Datang ke kantor desa pada jam layanan dengan membawa dokumen yang diminta.',

            LetterRequest::STATUS_ISSUED => 'Surat Anda sudah dapat diunduh dari halaman '
                .'pengajuan, dan lembar aslinya diambil di kantor desa.',

            LetterRequest::STATUS_REJECTED => 'Alasan penolakan: '
                .($this->request->rejection_reason ?? 'lihat halaman pengajuan.')
                .' Anda dapat mengajukan kembali setelah alasannya teratasi.',

            LetterRequest::STATUS_CANCELLED => 'Pengajuan dengan nomor tiket '.$tiket
                .' dibatalkan. Hubungi kantor desa bila ini di luar sepengetahuan Anda.',

            default => 'Status pengajuan '.$tiket.' berubah.',
        };
    }
}
