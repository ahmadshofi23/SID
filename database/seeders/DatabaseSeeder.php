<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ReferenceSeeder::class,
            PermissionSeeder::class,
            RoleSeeder::class,
            VillageSeeder::class,
            LetterTypeSeeder::class,
        ]);

        /*
         * Data contoh (penduduk & akun demo) TIDAK dijalankan di produksi.
         * Akun administrator pertama dibuat lewat perintah interaktif:
         *   php artisan sid:create-admin
         * sehingga tidak ada kata sandi bawaan di dalam kode (docs/08 §3).
         */
        if (app()->environment('local', 'testing')) {
            $this->call(DemoDataSeeder::class);
        }
    }
}
