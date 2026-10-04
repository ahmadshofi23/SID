<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Menegakkan sifat append-only audit log di tingkat hak akses MySQL — ADR-005.
 *
 * Model AuditLog sudah menolak update/delete di lapis aplikasi, tetapi itu
 * dapat dilewati oleh query builder atau SQL manual. Lapis inilah yang membuat
 * audit log benar-benar bernilai sebagai bukti.
 */
final class HardenAuditLog extends Command
{
    protected $signature = 'sid:harden-audit-log {--user= : Pengguna MySQL aplikasi, mis. sid_app@localhost}';

    protected $description = 'Mencabut hak UPDATE & DELETE pada tabel audit_logs (wajib di produksi)';

    public function handle(): int
    {
        $database = (string) config('database.connections.mysql.database');
        $user = (string) ($this->option('user')
            ?: config('database.connections.mysql.username').'@localhost');

        [$name, $host] = str_contains($user, '@')
            ? explode('@', $user, 2)
            : [$user, 'localhost'];

        $this->components->info("Mengamankan {$database}.audit_logs untuk '{$name}'@'{$host}'");

        try {
            DB::statement(
                "REVOKE UPDATE, DELETE ON `{$database}`.`audit_logs` FROM '{$name}'@'{$host}'"
            );
            DB::statement(
                "GRANT INSERT, SELECT ON `{$database}`.`audit_logs` TO '{$name}'@'{$host}'"
            );
            DB::statement('FLUSH PRIVILEGES');
        } catch (Throwable $e) {
            $this->components->error('Gagal mengubah hak akses: '.$e->getMessage());
            $this->newLine();
            $this->line('Perintah ini memerlukan koneksi dengan hak GRANT (mis. root).');
            $this->line('Jalankan manual sebagai administrator MySQL:');
            $this->newLine();
            $this->line("  REVOKE UPDATE, DELETE ON `{$database}`.`audit_logs` FROM '{$name}'@'{$host}';");
            $this->line("  GRANT INSERT, SELECT ON `{$database}`.`audit_logs` TO '{$name}'@'{$host}';");
            $this->line('  FLUSH PRIVILEGES;');
            $this->newLine();

            return self::FAILURE;
        }

        $this->components->info('Selesai. Audit log sekarang append-only di tingkat basis data.');
        $this->line('Verifikasi: php artisan sid:verify-audit-chain --check-grants');

        return self::SUCCESS;
    }
}
