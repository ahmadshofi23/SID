<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Verifikasi keutuhan rantai hash audit log — ADR-005.
 *
 * Dijalankan terjadwal (mingguan). Rantai yang rusak berarti ada baris yang
 * diubah, dihapus, atau disisipkan di luar aplikasi — insiden keamanan
 * tingkat Sev-1 (docs/08 §9), bukan sekadar galat teknis.
 */
final class VerifyAuditChain extends Command
{
    protected $signature = 'sid:verify-audit-chain {--limit= : Periksa n baris pertama saja}
                                                   {--check-grants : Sekaligus uji bahwa UPDATE/DELETE benar-benar ditolak}';

    protected $description = 'Memeriksa apakah audit log pernah diubah di luar aplikasi';

    public function handle(AuditLogger $audit): int
    {
        $limit = $this->option('limit') === null ? null : (int) $this->option('limit');

        $this->components->info('Memeriksa rantai hash audit log...');
        $result = $audit->verifyChain($limit);

        if (! $result['valid']) {
            $this->components->error(sprintf(
                'RANTAI RUSAK pada baris id=%d (setelah %d baris sah diperiksa).',
                $result['broken_at'], $result['checked']
            ));
            $this->newLine();
            $this->line('Ini menandakan audit log diubah di luar aplikasi.');
            $this->line('Perlakukan sebagai insiden Sev-1 — ikuti docs/08-keamanan-privasi.md §9.');
            $this->line('JANGAN hapus atau perbaiki baris apa pun: itu adalah bukti.');

            return self::FAILURE;
        }

        $this->components->info("Rantai utuh. {$result['checked']} baris diperiksa.");

        if ($this->option('check-grants')) {
            return $this->checkGrants();
        }

        return self::SUCCESS;
    }

    /** Uji nyata: coba UPDATE & DELETE, harapkan DITOLAK basis data. */
    private function checkGrants(): int
    {
        $this->newLine();
        $this->components->info('Menguji hak akses basis data...');

        $failures = [];

        foreach (['UPDATE audit_logs SET action = action WHERE id = 0',
            'DELETE FROM audit_logs WHERE id = 0'] as $sql) {
            $operation = strtok($sql, ' ');

            try {
                DB::statement($sql);
                $failures[] = $operation;
            } catch (Throwable) {
                $this->line("  <fg=green>✓</> {$operation} ditolak basis data");
            }
        }

        if ($failures !== []) {
            $this->newLine();
            $this->components->error(
                'MASIH DIIZINKAN: '.implode(', ', $failures).
                '. Audit log belum append-only.'
            );
            $this->line('Jalankan: php artisan sid:harden-audit-log');

            return self::FAILURE;
        }

        $this->components->info('Audit log append-only — terverifikasi.');

        return self::SUCCESS;
    }
}
