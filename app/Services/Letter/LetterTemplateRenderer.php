<?php

declare(strict_types=1);

namespace App\Services\Letter;

use App\Support\HtmlSanitizer;
use App\Support\NumberToWords;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Perender template surat — docs/13 §2, FR-SRT-02.
 *
 * Sintaksisnya mirip Mustache dan SENGAJA dibatasi: hanya variabel, bagian
 * bersyarat, dan segelintir pemformat. Alasannya bukan kemalasan — template
 * surat disunting petugas desa dari peramban, jadi ia adalah MASUKAN PENGGUNA.
 * Mesin template umum (Blade, Twig) mengeksekusi kode, sehingga seorang
 * operator dapat menjalankan PHP di server lewat kotak editor. Perender ini
 * tidak mengeksekusi apa pun.
 *
 * Yang didukung:
 *   {{nama}}                       variabel
 *   {{form.nama_usaha}}            isian dinamis
 *   {{#form.catatan}}…{{/form.catatan}}   tampil bila terisi
 *   {{^form.catatan}}…{{/form.catatan}}   tampil bila KOSONG
 *   {{#upper}}{{nama}}{{/upper}}   pemformat
 */
final class LetterTemplateRenderer
{
    public function __construct(private readonly HtmlSanitizer $sanitizer) {}

    /** Pemformat yang boleh dipakai di template — docs/13 §2.4. */
    private const FORMATTERS = ['upper', 'lower', 'kapital', 'rupiah', 'angka_huruf', 'tanggal_panjang'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function render(string $template, array $data): string
    {
        $output = $this->renderFormatters($template, $data);
        $output = $this->renderSections($output, $data);
        $output = $this->renderVariables($output, $data);

        /*
         * Pembersihan dilakukan di SINI, satu titik yang dilewati setiap
         * surat — baik yang diterbitkan maupun yang hanya dipratinjau.
         *
         * Hasilnya ditampilkan dengan `v-html` di halaman cetak. Nilai
         * variabel sudah di-escape saat disisipkan, tetapi KERANGKA
         * templatnya adalah HTML yang ditulis petugas; tanpa langkah ini,
         * seorang Operator dapat menitipkan <script> yang berjalan di
         * peramban Kepala Desa (lihat App\Support\HtmlSanitizer).
         */
        return $this->sanitizer->clean($output);
    }

    /**
     * Daftar variabel yang dipakai sebuah template.
     *
     * Dipakai editor template untuk menolak simpan bila ada variabel tak
     * dikenal (FR-SRT-02). Surat resmi yang tercetak dengan `{{xxx}}` masih
     * terbaca adalah kerusakan yang terlihat warga.
     *
     * @return array<int, string>
     */
    public function variablesUsed(string $template): array
    {
        preg_match_all('/\{\{[#^\/]?\s*([a-zA-Z0-9_.]+)\s*\}\}/', $template, $matches);

        $names = array_unique($matches[1]);
        $names = array_diff($names, self::FORMATTERS);

        return array_values($names);
    }

    /**
     * Variabel yang tidak dikenal oleh konteks data.
     *
     * @param  array<string, mixed>  $knownData
     * @param  array<int, string>  $formFields  nama field dari form_schema
     * @return array<int, string>
     */
    public function unknownVariables(string $template, array $knownData, array $formFields = []): array
    {
        $known = array_keys($knownData);

        foreach ($formFields as $field) {
            $known[] = 'form.'.$field;
        }

        return array_values(array_diff($this->variablesUsed($template), $known));
    }

    /**
     * Pemformat diproses LEBIH DULU, karena isinya mengandung variabel yang
     * harus dirender dulu agar ada yang diformat.
     *
     * @param  array<string, mixed>  $data
     */
    private function renderFormatters(string $template, array $data): string
    {
        $pattern = '/\{\{#('.implode('|', self::FORMATTERS).')\}\}(.*?)\{\{\/\1\}\}/s';

        return (string) preg_replace_callback($pattern, function (array $m) use ($data): string {
            $inner = $this->renderVariables($m[2], $data);

            return $this->applyFormatter($m[1], $inner);
        }, $template);
    }

    private function applyFormatter(string $name, string $value): string
    {
        $trimmed = trim($value);

        return match ($name) {
            'upper' => Str::upper($trimmed),
            'lower' => Str::lower($trimmed),
            'kapital' => Str::title(Str::lower($trimmed)),
            'rupiah' => $trimmed === ''
                ? ''
                : 'Rp '.number_format((float) preg_replace('/[^0-9.\-]/', '', $trimmed), 0, ',', '.'),
            'angka_huruf' => $trimmed === ''
                ? ''
                : NumberToWords::rupiah((int) preg_replace('/[^0-9\-]/', '', $trimmed)),
            'tanggal_panjang' => $trimmed === ''
                ? ''
                : self::longDate($trimmed),
            default => $trimmed,
        };
    }

    /**
     * Bagian bersyarat — {{#x}}…{{/x}} dan {{^x}}…{{/x}}.
     *
     * @param  array<string, mixed>  $data
     */
    private function renderSections(string $template, array $data): string
    {
        /*
         * Dijalankan berulang agar bagian bersarang ikut terproses. Batas
         * lima putaran mencegah template yang tak lazim membuat proses
         * berputar tanpa henti — template berasal dari masukan pengguna.
         */
        for ($pass = 0; $pass < 5; $pass++) {
            $before = $template;

            $template = (string) preg_replace_callback(
                '/\{\{([#^])\s*([a-zA-Z0-9_.]+)\s*\}\}(.*?)\{\{\/\s*\2\s*\}\}/s',
                function (array $m) use ($data): string {
                    $filled = $this->isFilled($this->lookup($data, $m[2]));
                    $show = $m[1] === '#' ? $filled : ! $filled;

                    return $show ? $m[3] : '';
                },
                $template,
            );

            if ($template === $before) {
                break;
            }
        }

        return $template;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderVariables(string $template, array $data): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            function (array $m) use ($data): string {
                $value = $this->lookup($data, $m[1]);

                /*
                 * Variabel tak dikenal dikosongkan, BUKAN dibiarkan utuh.
                 * Jaring pengaman lapis dua setelah validasi editor: lebih
                 * baik ada ruang kosong di surat daripada tulisan
                 * "{{penghasilan_ayah}}" tercetak pada dokumen resmi.
                 */
                if ($value === null || $value === false) {
                    return '';
                }

                return e(is_scalar($value) ? (string) $value : '');
            },
            $template,
        );
    }

    /** @param  array<string, mixed>  $data */
    private function lookup(array $data, string $path): mixed
    {
        return data_get($data, $path);
    }

    private function isFilled(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        return ! in_array($value, [null, [], 0, '0'], true);
    }

    /** Tanggal panjang Indonesia: 1 Oktober 2026. */
    public static function longDate(string|Carbon $date): string
    {
        $carbon = $date instanceof Carbon ? $date : Carbon::parse($date);

        return $carbon->day.' '.self::MONTHS[(int) $carbon->format('n')].' '.$carbon->year;
    }

    /** @var array<int, string> */
    public const MONTHS = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];
}
