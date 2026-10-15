<?php

declare(strict_types=1);

namespace App\Services\Letter;

use App\Models\LetterType;
use App\Models\Setting;
use App\Models\VillageProfile;
use App\Support\VillageTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Penomoran surat — FR-SRT-08, ADR-001.
 *
 * BAGIAN PALING KRITIS DI MODUL PERSURATAN.
 *
 * Nomor surat ganda atau terlewat adalah temuan audit administrasi desa, dan
 * merusak kepercayaan pada seluruh dokumen yang pernah terbit. Karena itu ada
 * tiga lapis pengaman:
 *
 *   1. Nomor diterbitkan HANYA pada transisi ke `diterbitkan`, di dalam
 *      transaksi yang sama dengan penyimpanan suratnya.
 *   2. Penghitung dikunci dengan `SELECT ... FOR UPDATE`, sehingga dua petugas
 *      yang menyetujui bersamaan tetap mendapat nomor berbeda.
 *   3. Indeks unik (letter_type_id, issued_year, sequence_number) di tabel
 *      `letters` menolak nomor ganda walau kode di sini keliru.
 *
 * MENGAPA BUKAN AUTO_INCREMENT: ia tidak transaksional. Bila transaksi batal,
 * nomornya hilang dan deret berlubang. Audit desa tidak menerima nomor terlewat.
 */
final class LetterNumberService
{
    /** Bulan dalam angka Romawi — lazim dipakai pada penomoran surat desa. */
    private const ROMAN_MONTHS = [
        1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI',
        7 => 'VII', 8 => 'VIII', 9 => 'IX', 10 => 'X', 11 => 'XI', 12 => 'XII',
    ];

    /**
     * Ambil nomor urut berikutnya. WAJIB dipanggil di dalam transaksi.
     *
     * @return array{0: int, 1: string} [nomor urut, nomor surat terformat]
     */
    public function allocate(LetterType $type, ?Carbon $issuedAt = null): array
    {
        if (DB::transactionLevel() === 0) {
            /*
             * Pagar keras, bukan sekadar catatan dokumentasi.
             *
             * Di luar transaksi, `FOR UPDATE` tidak menahan kunci apa pun:
             * ia dilepas seketika setelah pernyataannya selesai. Dua
             * permintaan bersamaan akan sama-sama membaca nilai yang sama
             * dan menghasilkan nomor ganda — gejala yang hanya muncul saat
             * kantor desa sedang sibuk, dan nyaris mustahil ditelusuri
             * belakangan.
             */
            throw new RuntimeException(
                'LetterNumberService::allocate() harus dipanggil di dalam transaksi. '
                .'Di luar transaksi, penguncian penghitung tidak berlaku dan nomor '
                .'surat dapat ganda.'
            );
        }

        $issuedAt ??= now();

        /*
         * Tahun penghitung memakai KALENDER DESA, bukan UTC.
         *
         * Surat yang terbit 1 Januari pukul 02.00 WIB adalah 31 Desember
         * 19.00 UTC. Memakai tahun UTC di sini membuat nomornya diambil dari
         * deret tahun lalu, sementara nomor yang tercetak — yang juga
         * memakai kalender desa — menyebut tahun baru. Dua surat berbeda
         * akhirnya bernomor sama.
         */
        $year = VillageTime::year($issuedAt);
        $counterKey = $this->counterKey($type);

        /*
         * JEBAKAN MySQL — jangan dihapus baris INSERT ini.
         *
         * `SELECT ... FOR UPDATE` pada baris yang BELUM ADA tidak mengunci
         * apa pun. Tanpa menyisipkan baris penghitung lebih dulu, dua surat
         * pertama di awal tahun dapat sama-sama lolos dan memperoleh nomor 1.
         *
         * Diuji oleh TC-SRT-08 varian (b) — lihat docs/09-testing-qa.md §5.
         */
        DB::statement(
            'INSERT INTO letter_counters (letter_type_id, year, last_number)
             VALUES (?, ?, 0)
             ON DUPLICATE KEY UPDATE letter_type_id = letter_type_id',
            [$counterKey, $year],
        );

        $current = DB::selectOne(
            'SELECT last_number FROM letter_counters
             WHERE letter_type_id = ? AND year = ?
             FOR UPDATE',
            [$counterKey, $year],
        );

        $next = (int) ($current->last_number ?? 0) + 1;

        DB::update(
            'UPDATE letter_counters SET last_number = ?
             WHERE letter_type_id = ? AND year = ?',
            [$next, $counterKey, $year],
        );

        return [$next, $this->format($type, $next, $issuedAt)];
    }

    /**
     * Susun nomor surat dari pola.
     *
     * Pola dapat diubah desa dari menu pengaturan tanpa rilis baru
     * (NFR-MNT-05). Token yang tidak dikenal dibiarkan apa adanya agar
     * kesalahan pola terlihat jelas di layar pratinjau — bukan menghilang
     * diam-diam dan baru ketahuan setelah surat tercetak.
     */
    public function format(LetterType $type, int $sequence, Carbon $issuedAt): string
    {
        $village = VillageProfile::current();
        $local = VillageTime::local($issuedAt);
        $month = (int) $local->format('n');

        $replacements = [
            '{urut}' => (string) $sequence,
            '{urut:2}' => str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
            '{urut:3}' => str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
            '{urut:4}' => str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            '{kode}' => $type->code,
            '{klasifikasi}' => (string) ($type->classification_code ?? ''),
            '{kode_desa}' => (string) ($village->code ?? ''),
            '{bulan}' => str_pad((string) $month, 2, '0', STR_PAD_LEFT),
            '{bulan_romawi}' => self::ROMAN_MONTHS[$month],
            '{tahun}' => $local->format('Y'),
            '{tahun2}' => $local->format('y'),
        ];

        $pattern = $type->number_pattern !== ''
            ? $type->number_pattern
            : (string) Setting::get('letter.number_pattern',
                '{urut}/{kode}/{kode_desa}/{bulan_romawi}/{tahun}');

        $number = strtr($pattern, $replacements);

        // Membersihkan sisa pemisah ganda bila sebuah token bernilai kosong,
        // mis. {klasifikasi} yang belum diisi desa.
        return trim(preg_replace('#/{2,}#', '/', $number) ?? $number, '/');
    }

    /**
     * Pratinjau nomor berikutnya TANPA mengambilnya.
     *
     * Dipakai di layar persetujuan agar petugas tahu nomor berapa yang akan
     * terbit. Sengaja tidak mengunci apa pun: ini hanya perkiraan, dan nomor
     * sebenarnya baru ditetapkan saat surat disetujui.
     */
    public function preview(LetterType $type, ?Carbon $issuedAt = null): string
    {
        $issuedAt ??= now();
        $year = VillageTime::year($issuedAt);

        $current = DB::table('letter_counters')
            ->where('letter_type_id', $this->counterKey($type))
            ->where('year', $year)
            ->value('last_number');

        return $this->format($type, (int) $current + 1, $issuedAt);
    }

    /**
     * Kunci penghitung.
     *
     * Mode `single` membuat seluruh jenis surat berbagi satu deret nomor —
     * praktik yang dipakai sebagian desa (docs/13 §3 pola C). Mode ini
     * mengubah arti penomoran secara mendasar, jadi ia disimpan sebagai
     * pengaturan desa dan tidak boleh diubah setelah surat pertama terbit.
     */
    private function counterKey(LetterType $type): string
    {
        return Setting::get('letter.counter_mode', 'per_type') === 'single'
            ? '-'
            : $type->id;
    }
}
