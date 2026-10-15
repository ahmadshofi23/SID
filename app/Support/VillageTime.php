<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\VillageProfile;
use Illuminate\Support\Carbon;

/**
 * Waktu menurut zona waktu desa.
 *
 * MENGAPA INI PERLU ADA: aplikasi berjalan dan menyimpan waktu dalam UTC
 * (config/app.php) — itu benar untuk penyimpanan. Tetapi beberapa keputusan
 * harus memakai KALENDER DI KANTOR DESA, bukan kalender UTC:
 *
 *   - Nomor surat memuat bulan dan tahun. Surat yang diterbitkan
 *     1 Januari pukul 02.00 WIB adalah 31 Desember 19.00 UTC; tanpa koreksi
 *     ini, surat itu bernomor ".../XII/2026" sementara buku agenda desa
 *     mencatatnya sebagai surat pertama tahun 2027.
 *   - Penghitung nomor dikunci per tahun. Tahun yang berbeda antara
 *     penghitung dan nomor tercetak menghasilkan deret ganda di pergantian
 *     tahun — temuan audit yang hanya muncul sekali setahun dan sangat sulit
 *     ditelusuri belakangan.
 *   - Batas waktu SLA adalah "akhir hari kerja". Akhir hari di UTC jatuh pukul
 *     07.00 pagi berikutnya di Indonesia, sehingga laporan SLA menganggap
 *     surat yang terlambat masih tepat waktu.
 *
 * Zona waktunya diambil dari `village_profile.timezone`, bukan ditulis tetap
 * sebagai Asia/Jakarta: Indonesia punya tiga zona waktu (WIB/WITA/WIT).
 */
final class VillageTime
{
    public static function zone(): string
    {
        $zone = VillageProfile::current()->timezone;

        return is_string($zone) && $zone !== '' ? $zone : 'Asia/Jakarta';
    }

    /** Saat ini, menurut jam dinding kantor desa. */
    public static function now(): Carbon
    {
        return Carbon::now(self::zone());
    }

    /** Waktu apa pun, dilihat dari jam dinding kantor desa. */
    public static function local(Carbon $moment): Carbon
    {
        return $moment->copy()->setTimezone(self::zone());
    }

    /** Tahun menurut kalender desa. */
    public static function year(Carbon $moment): int
    {
        return (int) self::local($moment)->format('Y');
    }

    /** Bulan (1–12) menurut kalender desa. */
    public static function month(Carbon $moment): int
    {
        return (int) self::local($moment)->format('n');
    }

    /**
     * Akhir hari kerja ke-N dari suatu saat, dikembalikan dalam UTC.
     *
     * Dihitung di zona desa lalu dikonversi, bukan sebaliknya: "hari kerja"
     * dan "akhir hari" adalah gagasan yang hanya punya arti pada kalender
     * setempat.
     */
    public static function endOfWorkingDay(Carbon $from, int $workingDays): Carbon
    {
        $cursor = self::local($from);
        $remaining = max(1, $workingDays);

        while ($remaining > 0) {
            $cursor->addDay();

            if (! $cursor->isWeekend()) {
                $remaining--;
            }
        }

        return $cursor->endOfDay()->utc();
    }
}
