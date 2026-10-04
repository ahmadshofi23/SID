<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class GeneratePiiKeys extends Command
{
    protected $signature = 'sid:generate-pii-keys';

    protected $description = 'Membuat kunci enkripsi & pepper untuk data pribadi (NIK, nomor KK)';

    public function handle(): int
    {
        $key = 'base64:'.base64_encode(random_bytes(32));
        $pepper = bin2hex(random_bytes(32));

        $this->newLine();
        $this->line('Tambahkan dua baris berikut ke berkas .env Anda:');
        $this->newLine();
        $this->line("PII_ENCRYPTION_KEY={$key}");
        $this->line("PII_HASH_PEPPER={$pepper}");
        $this->newLine();

        $this->components->warn('BACA INI SEBELUM MELANJUTKAN:');
        $this->line('  1. Kehilangan PII_ENCRYPTION_KEY berarti SEMUA NIK dan nomor KK');
        $this->line('     tidak dapat dibaca lagi. Tidak ada cara memulihkannya.');
        $this->line('  2. Mengubah PII_HASH_PEPPER membatalkan seluruh indeks pencarian');
        $this->line('     NIK dan penegakan keunikan — perlu enkripsi ulang seluruh data.');
        $this->line('  3. Simpan cadangan kedua kunci di tempat TERPISAH dari cadangan');
        $this->line('     basis data. Menyimpannya bersama-sama membuat enkripsi sia-sia.');
        $this->newLine();

        return self::SUCCESS;
    }
}
