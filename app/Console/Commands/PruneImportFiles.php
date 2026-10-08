<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ImportJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Hapus berkas impor yang sudah tidak diperlukan — menutup R-20.
 *
 * Berkas impor memuat NIK ribuan warga. Setelah impor selesai, ia tidak lagi
 * punya alasan untuk tetap ada: ringkasan dan daftar galat sudah tersimpan di
 * basis data, dan datanya sendiri sudah masuk ke tabel penduduk.
 *
 * Membiarkannya menumpuk berarti setiap impor menambah satu salinan data
 * pribadi di server — tempat kebocoran yang bertambah sendiri tanpa ada yang
 * menyadarinya.
 */
final class PruneImportFiles extends Command
{
    protected $signature = 'sid:prune-import-files {--days=7 : Usia berkas yang dihapus}
                                                   {--dry-run : Tampilkan saja, jangan hapus}';

    protected $description = 'Menghapus berkas impor yang sudah selesai diproses (R-20)';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $dryRun = (bool) $this->option('dry-run');

        $jobs = ImportJob::query()
            ->whereIn('status', [
                ImportJob::STATUS_DONE,
                ImportJob::STATUS_FAILED,
                ImportJob::STATUS_CANCELLED,
            ])
            ->where('updated_at', '<', now()->subDays($days))
            ->get();

        $deleted = 0;

        foreach ($jobs as $job) {
            if ($job->storage_path === '' || ! Storage::disk('local')->exists($job->storage_path)) {
                continue;
            }

            $this->line(sprintf(
                '  %s %s (%s)',
                $dryRun ? '[akan dihapus]' : 'dihapus',
                $job->original_name,
                $job->status,
            ));

            if (! $dryRun) {
                Storage::disk('local')->delete($job->storage_path);

                // Jalurnya dikosongkan agar tidak ada rujukan ke berkas yang
                // sudah tiada — riwayat impornya sendiri tetap tersimpan.
                $job->update(['storage_path' => '']);
            }

            $deleted++;
        }

        $this->newLine();
        $this->components->info($dryRun
            ? "{$deleted} berkas akan dihapus (mode uji coba)."
            : "{$deleted} berkas impor dihapus.");

        return self::SUCCESS;
    }
}
