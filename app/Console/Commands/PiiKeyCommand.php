<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

use function Laravel\Prompts\password;

use SensitiveParameter;

/**
 * Alat bantu pencadangan kunci data pribadi — KEP-006, menutup risiko R-18.
 *
 * KEPUTUSAN RANCANGAN: perintah ini TIDAK PERNAH menampilkan kunci.
 *
 * Godaannya besar: membuat perintah `sid:show-pii-key` yang mencetak kunci agar
 * mudah disalin. Tetapi kunci yang pernah tampil di layar akan tertinggal di
 * riwayat terminal, di rekaman layar saat rapat daring, di tangkapan layar
 * dukungan teknis, dan di log sesi SSH. Kunci dibaca langsung dari berkas .env
 * oleh orang yang berwenang; perintah ini hanya membantu MEMASTIKAN salinan
 * kertasnya benar — bagian yang paling mudah salah dan paling berakibat fatal.
 */
final class PiiKeyCommand extends Command
{
    protected $signature = 'sid:pii-key
                            {--verify : Periksa apakah salinan kertas Anda cocok dengan kunci di server}';

    protected $description = 'Sidik jari kunci data pribadi, dan pemeriksaan salinan cadangan';

    public function handle(): int
    {
        $key = (string) config('sid.pii.key');
        $pepper = (string) config('sid.pii.pepper');

        if ($key === '' || $pepper === '') {
            $this->components->error(
                'PII_ENCRYPTION_KEY atau PII_HASH_PEPPER belum diisi di .env.'
            );
            $this->line('Buat dengan: php artisan sid:generate-pii-keys');

            return self::FAILURE;
        }

        return $this->option('verify')
            ? $this->verify($key, $pepper)
            : $this->showFingerprints($key, $pepper);
    }

    /**
     * Tampilkan sidik jari — ringkasan pendek yang aman dilihat orang lain.
     *
     * Sidik jari dihitung dari kunci, tetapi tidak dapat dibalik menjadi kunci.
     * Gunanya: memastikan dua kertas memuat kunci yang sama, dan memastikan
     * server masih memakai kunci yang sama dengan yang dicadangkan — tanpa
     * perlu menyebut kuncinya.
     */
    private function showFingerprints(
        #[SensitiveParameter] string $key,
        #[SensitiveParameter] string $pepper,
    ): int {
        $this->newLine();
        $this->components->info('Sidik jari kunci data pribadi');
        $this->newLine();

        $this->line('  Kunci enkripsi  : <options=bold>'.$this->fingerprint($key).'</>');
        $this->line('  Pepper pencarian: <options=bold>'.$this->fingerprint($pepper).'</>');
        $this->newLine();

        $this->line('  Sidik jari ini AMAN dilihat orang lain — ia tidak dapat');
        $this->line('  dikembalikan menjadi kunci aslinya.');
        $this->newLine();
        $this->line('  Tulis juga sidik jari ini di kertas cadangan Anda. Dengan');
        $this->line('  begitu, kapan pun Anda dapat memastikan kertas itu masih');
        $this->line('  cocok dengan yang dipakai server, tanpa membuka amplopnya.');
        $this->newLine();
        $this->line('  Memeriksa salinan kertas : php artisan sid:pii-key --verify');
        $this->line('  Prosedur lengkap         : ops/runbook/pencadangan-kunci.md');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Periksa salinan kertas.
     *
     * Masukan diketik secara tersembunyi dan dibandingkan dengan
     * hash_equals() — perbandingan yang waktunya tetap, sehingga tidak
     * membocorkan sebagian kunci lewat selisih waktu respons.
     */
    private function verify(
        #[SensitiveParameter] string $key,
        #[SensitiveParameter] string $pepper,
    ): int {
        $this->newLine();
        $this->line('  Ketik ulang kunci dari kertas cadangan Anda.');
        $this->line('  Yang Anda ketik tidak akan tampil di layar.');
        $this->newLine();

        $typedKey = password(
            label: 'PII_ENCRYPTION_KEY dari kertas',
            hint: 'Termasuk awalan "base64:"',
            required: true,
        );

        $typedPepper = password(
            label: 'PII_HASH_PEPPER dari kertas',
            required: true,
        );

        $keyOk = hash_equals($key, trim($typedKey));
        $pepperOk = hash_equals($pepper, trim($typedPepper));

        $this->newLine();
        $this->line('  Kunci enkripsi  : '.($keyOk
            ? '<fg=green>cocok</>' : '<fg=red>TIDAK COCOK</>'));
        $this->line('  Pepper pencarian: '.($pepperOk
            ? '<fg=green>cocok</>' : '<fg=red>TIDAK COCOK</>'));
        $this->newLine();

        if ($keyOk && $pepperOk) {
            $this->components->info(
                'Salinan kertas Anda benar. Simpan di tempat yang sudah disepakati.'
            );

            return self::SUCCESS;
        }

        $this->components->error('Salinan kertas TIDAK cocok dengan kunci di server.');
        $this->newLine();
        $this->line('  Kemungkinan terbesar adalah salah salin. Periksa karakter yang');
        $this->line('  mudah tertukar:');
        $this->line('    angka 0 dengan huruf O     huruf l kecil dengan angka 1');
        $this->line('    huruf I besar dengan l     tanda + dengan /    akhiran =');
        $this->newLine();
        $this->line('  Tulis ulang kertasnya, lalu jalankan perintah ini kembali.');
        $this->line('  JANGAN menyimpan kertas yang belum terverifikasi.');
        $this->newLine();

        return self::FAILURE;
    }

    /** Sidik jari pendek: 16 karakter heksadesimal, dikelompokkan agar mudah dibaca. */
    private function fingerprint(#[SensitiveParameter] string $value): string
    {
        $hash = substr(hash('sha256', $value), 0, 16);

        return implode('-', str_split(strtoupper($hash), 4));
    }
}
