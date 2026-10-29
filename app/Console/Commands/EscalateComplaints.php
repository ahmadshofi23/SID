<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Repositories\ComplaintRepository;
use App\Services\Complaint\ComplaintService;
use Illuminate\Console\Command;

/**
 * Eskalasi otomatis pengaduan yang melewati SLA — FR-PGD-06.
 *
 * APA YANG PERINTAH INI *TIDAK* LAKUKAN, dan itu bagian terpenting dari
 * rancangannya: ia tidak menandai pengaduan sebagai terlambat. Keterlambatan
 * SELALU dihitung dari kueri (ComplaintRepository::whereOverdue()), sehingga
 * antrean dan dashboard tetap benar walau perintah ini tidak pernah berjalan.
 * Pola yang sama dengan penjadwalan terbit konten portal (Sprint 6): yang
 * dijaga kueri tidak dapat menjadi usang.
 *
 * Yang dilakukannya hanya dua hal yang MEMANG menuntut pemicu: menaikkan
 * prioritas, dan mengirim notifikasi. Keduanya tidak dapat "dihitung" —
 * notifikasi harus dikirim pada suatu saat tertentu oleh seseorang.
 *
 * Konsekuensi dari pembagian ini: cron yang mati membuat desa kehilangan
 * PERINGATAN, bukan kehilangan kebenaran. Tidak ada satu angka pun di layar
 * yang menjadi salah karenanya.
 *
 * Idempoten: hanya pengaduan yang prioritasnya belum tinggi yang terambil,
 * jadi menjalankannya dua kali sehari tidak mengirim dua notifikasi untuk
 * keterlambatan yang sama.
 */
final class EscalateComplaints extends Command
{
    protected $signature = 'sid:eskalasi-pengaduan {--dry-run : Tampilkan saja, jangan ubah}';

    protected $description = 'Menaikkan prioritas & memberi tahu petugas untuk pengaduan yang melewati SLA (FR-PGD-06)';

    public function handle(ComplaintRepository $complaints, ComplaintService $service): int
    {
        $overdue = $complaints->overdueForEscalation();

        if ($overdue->isEmpty()) {
            $this->info('Tidak ada pengaduan yang melewati batas waktu.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $escalated = 0;

        foreach ($overdue as $complaint) {
            $this->line(sprintf(
                '  %s — %s (%s, batas %s)',
                $complaint->ticket_number,
                mb_strimwidth($complaint->title, 0, 50, '…'),
                $complaint->status,
                $complaint->activeDueAt()?->toDateString() ?? '-',
            ));

            if ($dryRun) {
                continue;
            }

            if ($service->escalate($complaint)) {
                $escalated++;
            }
        }

        $this->info($dryRun
            ? sprintf('%d pengaduan akan dieskalasi (dry-run).', $overdue->count())
            : sprintf('%d pengaduan dieskalasi.', $escalated));

        return self::SUCCESS;
    }
}
