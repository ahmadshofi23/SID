<?php

declare(strict_types=1);

namespace App\Services\Complaint;

use App\Models\Complaint;
use App\Models\Region;
use App\Models\User;
use App\Notifications\ComplaintAssigned;
use App\Notifications\ComplaintAwaitingTriage;
use App\Notifications\ComplaintEscalated;
use App\Notifications\ComplaintStatusChanged;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Penentu SIAPA yang diberi tahu tentang pengaduan — FR-PGD-04, FR-PGD-06.
 *
 * Dipisah dari kelas notifikasi (yang menentukan APA isinya) dan dari
 * ComplaintService (yang menentukan KAPAN), mengikuti LetterNotifier.
 *
 * Pada pengaduan, pemisahan ini menanggung satu beban yang tidak ada pada
 * persuratan: SEBAGIAN PELAPOR TIDAK BOLEH DIBERI TAHU. Pelapor anonim tidak
 * punya akun yang tertaut kepadanya — itu seluruh maksud RULE-09 — sehingga
 * tidak ada siapa pun untuk dikirimi notifikasi, dan mencari-carinya justru
 * berarti melanggar janji yang dibuat kepadanya. Jalurnya halaman pelacakan
 * dengan kode, dan hanya itu.
 */
final class ComplaintNotifier
{
    /** Pelapor diberi tahu status pengaduannya berubah — FR-PGD-03. */
    public function statusChanged(Complaint $complaint, string $fromStatus): void
    {
        $penerima = $this->reporterAccount($complaint);

        if ($penerima === null) {
            return;
        }

        $penerima->notify(new ComplaintStatusChanged($complaint, $fromStatus));
    }

    /** Pelapor diberi tahu ada tindak lanjut baru yang dapat ia baca — FR-PGD-05. */
    public function followUp(Complaint $complaint, string $note): void
    {
        $penerima = $this->reporterAccount($complaint);

        $penerima?->notify(new ComplaintStatusChanged($complaint, $complaint->status, $note));
    }

    /** Petugas penerima tugas diberi tahu — FR-PGD-04. */
    public function assigned(Complaint $complaint, User $assignee): void
    {
        $assignee->notify(new ComplaintAssigned($complaint));
    }

    /**
     * Pengaduan baru masuk: yang diberi tahu adalah petugas yang dapat
     * MENTRIASENYA, bukan seluruh pemilik izin pengaduan.
     *
     * Peran yang hanya boleh melihat (Kepala Desa, Pengawas) sengaja tidak
     * ikut: notifikasi tentang pekerjaan yang tidak dapat mereka kerjakan akan
     * membuat lonceng mereka selalu merah, dan lonceng yang selalu merah
     * berhenti dibaca — termasuk ketika yang masuk benar-benar mendesak.
     */
    public function awaitingTriage(Complaint $complaint): void
    {
        $penerima = $this->followUpOfficers($complaint);

        if ($penerima->isEmpty()) {
            return;
        }

        Notification::send($penerima, new ComplaintAwaitingTriage($complaint));
    }

    /**
     * Eskalasi SLA — FR-PGD-06.
     *
     * Dikirim ke penerima tugas DAN ke petugas lain yang dapat
     * menindaklanjuti. Keduanya perlu: pengaduan yang terlambat karena
     * penerima tugasnya sedang tidak masuk hanya akan tertolong bila ada orang
     * lain yang melihatnya. Pengaduan yang BELUM ditugaskan sama sekali —
     * yang terlambat di tahap triase — justru kasus terpentingnya, dan di situ
     * tidak ada penerima tugas untuk diberi tahu.
     */
    public function escalated(Complaint $complaint): void
    {
        $penerima = $this->followUpOfficers($complaint);

        if ($complaint->assigned_to !== null) {
            $assignee = User::query()
                ->where('status', User::STATUS_ACTIVE)
                ->whereKey($complaint->assigned_to)
                ->first();

            if ($assignee !== null && ! $penerima->contains('id', $assignee->id)) {
                $penerima->push($assignee);
            }
        }

        if ($penerima->isEmpty()) {
            return;
        }

        Notification::send($penerima, new ComplaintEscalated($complaint));
    }

    /**
     * Akun pelapor, bila ada.
     *
     * Dua keadaan mengembalikan null, dan keduanya NORMAL — bukan kegagalan:
     * pengaduan anonim (tidak boleh punya tautan ke akun, RULE-09) dan
     * pengaduan yang dicatat petugas atas nama warga yang belum punya akun.
     * Keduanya tetap terlacak lewat kode pelacakan.
     */
    private function reporterAccount(Complaint $complaint): ?User
    {
        if ($complaint->is_anonymous || $complaint->reporter_user_id === null) {
            return null;
        }

        return User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->whereKey($complaint->reporter_user_id)
            ->first();
    }

    /**
     * Petugas yang dapat menindaklanjuti pengaduan, dibatasi wilayahnya.
     *
     * Kepala Dusun hanya diberi tahu tentang pengaduan DI DUSUNNYA — sama
     * dengan apa yang dapat ia lihat lewat ComplaintRepository. Notifikasi
     * tentang pengaduan yang kemudian menghasilkan 404 ketika ia menekannya
     * bukan sekadar mengganggu: ia memberi tahu bahwa ada pengaduan di tempat
     * lain, yaitu kebocoran yang justru dicegah aturan 404 (docs/06 §4).
     *
     * @return Collection<int, User>
     */
    private function followUpOfficers(Complaint $complaint): Collection
    {
        return User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->whereHas('roles.permissions', fn (Builder $q) => $q
                ->where('code', 'pengaduan.tindaklanjut'))
            ->where(function (Builder $q) use ($complaint): void {
                // Tidak dibatasi wilayah: lingkupnya seluruh desa.
                $q->whereDoesntHave('roles', fn (Builder $r) => $r
                    ->whereNotNull('user_roles.region_id'));

                if ($complaint->region_id !== null) {
                    $q->orWhereHas('roles', fn (Builder $r) => $r
                        ->whereIn('user_roles.region_id', $this->regionAncestry($complaint)));
                }
            })
            ->get();
    }

    /**
     * Id wilayah pengaduan beserta SELURUH INDUKNYA.
     *
     * Kepala Dusun ditugaskan pada DUSUN, sementara pengaduan sering menyebut
     * RT. Mencocokkan region_id secara langsung berarti ia tidak pernah
     * diberi tahu tentang pengaduan di RT-nya sendiri — padahal justru itu
     * wilayahnya.
     *
     * Dihitung dari `path` yang berbentuk "dusun-krajan/rw-001/rt-003"
     * (docs/05 §3.2): setiap awalan path adalah satu induk. Ini cerminan dari
     * ComplaintRepository::applyScope(), yang menyaring dengan arah sebaliknya
     * (path wilayah petugas sebagai awalan). Keduanya HARUS sepakat, dan itu
     * sebabnya keduanya memakai `path` dan bukan rantai parent_id.
     *
     * @return list<string>
     */
    private function regionAncestry(Complaint $complaint): array
    {
        $region = $complaint->region;

        if ($region === null) {
            return [];
        }

        $segments = array_values(array_filter(explode('/', $region->path)));
        $paths = [];
        $prefix = '';

        foreach ($segments as $segment) {
            $prefix = $prefix === '' ? $segment : $prefix.'/'.$segment;
            $paths[] = $prefix;
        }

        /** @var list<string> */
        return Region::query()->whereIn('path', $paths)->pluck('id')->all();
    }
}
