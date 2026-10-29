<?php

declare(strict_types=1);

namespace App\Services\Letter;

use App\Models\Letter;
use App\Models\LetterRequest;
use App\Models\Role;
use App\Models\User;
use App\Notifications\LetterCancelled;
use App\Notifications\LetterRequestAwaitingAction;
use App\Notifications\LetterRequestStatusChanged;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Penentu SIAPA yang diberi tahu — FR-SRT-14, BR-11, UC-05.
 *
 * Dipisah dari kelas notifikasi (yang menentukan APA isinya) dan dari
 * LetterRequestService (yang menentukan KAPAN). Pemisahan ini ada karena
 * pertanyaan "siapa pemohon surat ini sebenarnya" tidak sesederhana
 * kelihatannya: pengajuan dibuat ayah untuk anaknya, atau dibuat petugas
 * loket atas nama warga yang belum punya akun sama sekali.
 */
final class LetterNotifier
{
    /** Pemohon diberi tahu bahwa status pengajuannya berubah. */
    public function statusChanged(LetterRequest $request, string $fromStatus): void
    {
        $penerima = $this->applicantAccounts($request);

        if ($penerima->isEmpty()) {
            /*
             * Tidak ada akun yang perlu diberi tahu, dan itu KEADAAN NORMAL,
             * bukan kegagalan: sebagian besar pengajuan loket dibuat atas
             * nama warga yang belum mendaftar. Jalur mereka tetap halaman
             * pelacakan publik dengan nomor tiket (KEP-014 §5).
             */
            return;
        }

        Notification::send($penerima, new LetterRequestStatusChanged($request, $fromStatus));
    }

    /** Petugas pemeriksa diberi tahu ada pengajuan daring baru. */
    public function awaitingAction(LetterRequest $request): void
    {
        /*
         * HANYA pengajuan daring. Pengajuan loket dibuat petugas yang sedang
         * berdiri di depan warganya; memberitahunya tentang pekerjaan yang
         * baru saja ia buat sendiri hanya melatih orang mengabaikan lonceng.
         */
        if ($request->channel !== 'online') {
            return;
        }

        $role = $this->firstStepRole($request);

        if ($role === null) {
            return;
        }

        $petugas = User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->whereHas('roles', fn ($q) => $q->where('code', $role))
            ->get();

        if ($petugas->isNotEmpty()) {
            Notification::send($petugas, new LetterRequestAwaitingAction($request));
        }
    }

    /** Pemohon diberi tahu bahwa surat yang sudah terbit dibatalkan (RULE-05). */
    public function letterCancelled(Letter $letter, string $reason): void
    {
        $penerima = $this->applicantAccounts($letter->request);

        if ($penerima->isNotEmpty()) {
            Notification::send($penerima, new LetterCancelled($letter, $reason));
        }
    }

    /**
     * Akun yang berhak tahu tentang satu pengajuan.
     *
     * DUA kemungkinan, dan keduanya perlu:
     *   1. Akun milik PEMOHON-nya sendiri, bila ia punya.
     *   2. Akun yang MENGAJUKAN, bila berbeda — ayah yang mengurus surat
     *      anaknya adalah yang menunggu kabarnya, dan si anak sering belum
     *      punya akun sama sekali.
     *
     * Akun petugas yang membuat pengajuan loket SENGAJA tidak masuk: ia
     * melihat antreannya sendiri, dan satu notifikasi per surat yang ia
     * layani sepanjang hari akan menenggelamkan yang benar-benar penting.
     *
     * @return Collection<int, User>
     */
    private function applicantAccounts(LetterRequest $request): Collection
    {
        return User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->whereHas('roles', fn ($q) => $q->where('code', Role::WARGA))
            ->where(function ($q) use ($request): void {
                $q->where('resident_id', $request->resident_id)
                    ->orWhere('id', $request->requested_by ?? '-');
            })
            ->get();
    }

    /**
     * Peran yang memeriksa tahap pertama, dari alur persetujuan jenis surat.
     *
     * Diambil dari konfigurasi, bukan ditetapkan di kode: alur persetujuan
     * dapat diubah petugas per jenis surat tanpa rilis baru (FR-SRT-06), dan
     * daftar penerima notifikasi harus ikut berubah bersamanya.
     */
    private function firstStepRole(LetterRequest $request): ?string
    {
        $flow = $request->letterType->approval_flow;
        $first = $flow[0] ?? null;
        $role = is_array($first) ? ($first['role'] ?? null) : null;

        return is_string($role) && $role !== '' ? $role : null;
    }
}
