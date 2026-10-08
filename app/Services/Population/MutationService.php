<?php

declare(strict_types=1);

namespace App\Services\Population;

use App\Models\Resident;
use App\Models\ResidentMutation;
use App\Repositories\ResidentRepository;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mutasi penduduk — FR-PDK-10, BR-03.
 *
 * Mengapa modul ini penting melebihi tampilannya: inilah satu-satunya hal yang
 * menjaga agar jumlah penduduk tetap benar. Tanpa pencatatan mutasi, basis data
 * desa perlahan menyimpang dari kenyataan — orang yang sudah meninggal tetap
 * terhitung, dan selisihnya baru ketahuan saat laporan ke kecamatan ditolak.
 *
 * Setiap mutasi mengubah status penduduk SECARA OTOMATIS dan di dalam satu
 * transaksi. Mencatat kematian tanpa mengubah status akan menghasilkan dua
 * sumber kebenaran yang berbeda.
 */
final class MutationService
{
    public function __construct(
        private readonly ResidentRepository $residents,
        private readonly AuditLogger $audit,
        private readonly StatisticsService $statistics,
    ) {}

    /**
     * Catat kematian — status menjadi `meninggal` (RULE-03).
     *
     * @param  array<string, mixed>  $data
     */
    public function recordDeath(Resident $resident, array $data): ResidentMutation
    {
        $this->guardActive($resident, 'kematian');
        $this->guardNotBeforeBirth($resident, (string) $data['mutation_date']);

        return $this->withStatisticsRefresh(fn (): ResidentMutation => DB::transaction(function () use ($resident, $data): ResidentMutation {
            $mutation = $this->store($resident, ResidentMutation::TYPE_DEATH, [
                'mutation_date' => $data['mutation_date'],
                'death_cause' => $data['death_cause'] ?? null,
                'death_place' => $data['death_place'] ?? null,
                'document_no' => $data['document_no'] ?? null,
                'reported_by' => $data['reported_by'] ?? null,
                'reason' => $data['reason'] ?? null,
            ]);

            $resident->status = 'meninggal';
            $resident->save();

            /*
             * Peringatan, bukan penolakan: kepala keluarga memang bisa
             * meninggal sebelum penggantinya ditetapkan. Memblokir akan
             * membuat petugas tidak dapat mencatat kenyataan.
             */
            if ($resident->isHeadOfFamily() && $resident->family_id !== null) {
                $this->audit->log('family.head_vacant', $resident, context: [
                    'family_id' => $resident->family_id,
                    'sebab' => 'kepala keluarga meninggal',
                ]);
            }

            return $mutation;
        }));
    }

    /**
     * Catat kepindahan keluar — status menjadi `pindah_keluar`.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordMoveOut(Resident $resident, array $data): ResidentMutation
    {
        $this->guardActive($resident, 'kepindahan');

        return $this->withStatisticsRefresh(fn (): ResidentMutation => DB::transaction(function () use ($resident, $data): ResidentMutation {
            $mutation = $this->store($resident, ResidentMutation::TYPE_MOVE_OUT, [
                'mutation_date' => $data['mutation_date'],
                'origin_address' => $resident->address,
                'destination_address' => $data['destination_address'] ?? null,
                'reason' => $data['reason'] ?? null,
                'document_no' => $data['document_no'] ?? null,
                'reported_by' => $data['reported_by'] ?? null,
            ]);

            $resident->status = 'pindah_keluar';
            $resident->save();

            return $mutation;
        }));
    }

    /**
     * Catat kepindahan datang — penduduk yang sudah ada diaktifkan kembali.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordMoveIn(Resident $resident, array $data): ResidentMutation
    {
        return $this->withStatisticsRefresh(fn (): ResidentMutation => DB::transaction(function () use ($resident, $data): ResidentMutation {
            $mutation = $this->store($resident, ResidentMutation::TYPE_MOVE_IN, [
                'mutation_date' => $data['mutation_date'],
                'origin_address' => $data['origin_address'] ?? null,
                'destination_address' => $resident->address,
                'reason' => $data['reason'] ?? null,
                'document_no' => $data['document_no'] ?? null,
                'reported_by' => $data['reported_by'] ?? null,
            ]);

            $resident->status = Resident::STATUS_ACTIVE;
            $resident->save();

            return $mutation;
        }));
    }

    /**
     * Catat kelahiran untuk penduduk yang SUDAH dibuat.
     *
     * Bayi dimasukkan lewat modul kependudukan seperti penduduk lain, lalu
     * kelahirannya dicatat di sini. Menggabungkan keduanya menjadi satu
     * formulir terasa lebih ringkas, tetapi membuat aturan NIK dan kartu
     * keluarga harus ditulis dua kali — dan dua salinan aturan pasti berbeda
     * suatu saat.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordBirth(Resident $resident, array $data): ResidentMutation
    {
        return $this->withStatisticsRefresh(fn (): ResidentMutation => DB::transaction(fn (): ResidentMutation => $this->store(
            $resident,
            ResidentMutation::TYPE_BIRTH,
            [
                'mutation_date' => $data['mutation_date'] ?? $resident->birth_date->toDateString(),
                'birth_weight' => $data['birth_weight'] ?? null,
                'birth_length' => $data['birth_length'] ?? null,
                'birth_helper' => $data['birth_helper'] ?? null,
                'document_no' => $data['document_no'] ?? null,
                'reported_by' => $data['reported_by'] ?? null,
                'reason' => $data['reason'] ?? null,
            ],
        )));
    }

    /**
     * Daftar mutasi untuk laporan bulanan.
     *
     * @param  array<string, mixed>  $filters
     * @return CursorPaginator<int, ResidentMutation>
     */
    public function paginate(array $filters, int $perPage = 50): CursorPaginator
    {
        /*
         * Mutasi menyimpan resident_id, sehingga daftarnya WAJIB dibatasi
         * lingkup akses yang sama dengan tabel penduduk — bila tidak, Kepala
         * Dusun dapat membaca nama warga dusun lain lewat daftar mutasi.
         */
        $visibleIds = $this->residents->visibleIdsQuery();

        return ResidentMutation::query()
            ->with('resident:id,full_name,nik_masked,status')
            ->whereIn('resident_id', $visibleIds)
            ->when(! empty($filters['type']), fn ($q) => $q->where('type', $filters['type']))
            ->when(! empty($filters['from']), fn ($q) => $q->whereDate('mutation_date', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn ($q) => $q->whereDate('mutation_date', '<=', $filters['to']))
            ->orderByDesc('mutation_date')
            ->orderByDesc('id')
            ->cursorPaginate(min($perPage, 50));
    }

    /**
     * Jalankan aksi mutasi, lalu segarkan statistik.
     *
     * Penyegaran dilakukan SETELAH transaksi selesai, bukan di dalamnya.
     * Sebelumnya ia dipanggil di dalam store() — yaitu sebelum status penduduk
     * diubah — sehingga angka statistik selalu tertinggal satu langkah dan baru
     * benar setelah mutasi berikutnya.
     *
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     */
    private function withStatisticsRefresh(callable $action): mixed
    {
        $result = $action();

        $this->statistics->refresh();

        return $result;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function store(Resident $resident, string $type, array $attributes): ResidentMutation
    {
        $mutation = new ResidentMutation;
        $mutation->forceFill([
            ...$attributes,
            'resident_id' => $resident->id,
            'type' => $type,
            'created_by' => auth()->id() ?? 'sistem',
        ])->save();

        $this->audit->log("mutation.{$type}", $resident, context: [
            'tanggal' => $attributes['mutation_date'],
            'mutasi_id' => $mutation->id,
        ]);

        return $mutation;
    }

    private function guardActive(Resident $resident, string $what): void
    {
        if ($resident->status === Resident::STATUS_ACTIVE) {
            return;
        }

        throw ValidationException::withMessages([
            'resident_id' => sprintf(
                'Tidak dapat mencatat %s: %s sudah berstatus %s.',
                $what,
                $resident->full_name,
                str_replace('_', ' ', $resident->status),
            ),
        ]);
    }

    private function guardNotBeforeBirth(Resident $resident, string $date): void
    {
        if (strtotime($date) >= $resident->birth_date->getTimestamp()) {
            return;
        }

        throw ValidationException::withMessages([
            'mutation_date' => 'Tanggal kematian tidak boleh mendahului tanggal lahir.',
        ]);
    }
}
