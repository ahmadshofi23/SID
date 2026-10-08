<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ImportJob;
use App\Services\Import\ResidentImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pekerjaan impor di latar belakang — UC-04 langkah 3.
 *
 * Satu kelas untuk dua tahap, dibedakan oleh $stage. Memisahkannya menjadi dua
 * kelas akan menduplikasi penanganan galat dan pencarian pekerjaan tanpa
 * menambah kejelasan.
 */
final class ProcessResidentImport implements ShouldQueue
{
    use Queueable;

    /** Impor 20.000 baris pada VPS desa dapat memakan beberapa menit. */
    public int $timeout = 900;

    /**
     * TIDAK dicoba ulang otomatis.
     *
     * Mengulang impor yang gagal di tengah berisiko menggandakan data: baris
     * yang sudah masuk akan diproses lagi. Petugas memulai ulang secara sadar
     * setelah melihat sebabnya.
     */
    public int $tries = 1;

    public function __construct(
        public readonly string $importJobId,
        public readonly string $stage,
    ) {}

    public function handle(ResidentImporter $importer): void
    {
        $job = ImportJob::query()->find($this->importJobId);

        if ($job === null || $job->isFinished()) {
            return;
        }

        $this->stage === 'validate'
            ? $importer->preValidate($job)
            : $importer->execute($job);
    }

    public function failed(\Throwable $exception): void
    {
        ImportJob::query()->where('id', $this->importJobId)->update([
            'status' => ImportJob::STATUS_FAILED,
            'failure_reason' => $exception->getMessage(),
            'finished_at' => now(),
        ]);
    }
}
