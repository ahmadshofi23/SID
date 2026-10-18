<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Letter;
use App\Models\LetterMutationOffer;
use App\Models\LetterRequest;
use App\Models\Resident;
use App\Support\VillageTime;

/**
 * Tawaran mutasi SKL/SKM untuk lapis tampilan — docs/13 §5.4 & §5.5.
 *
 * Dipisah dari LetterRequestPresenter karena sasarannya BUKAN pemohon
 * melainkan orang lain: almarhum pada SKM, bayi yang belum ada pada SKL.
 * Menyusunnya di dalam presenter pengajuan akan mencampur dua orang berbeda
 * dalam satu payload, dan `nik_masked` yang tertukar di layar persetujuan
 * surat kematian bukan kekeliruan yang ingin ditemukan di produksi.
 */
final class LetterMutationOfferPresenter
{
    /**
     * @param  Resident|null  $target  Almarhum (SKM) — null bila di luar
     *                                 lingkup pengguna atau belum relevan.
     * @return array<string, mixed>|null
     */
    public static function make(
        LetterRequest $request,
        ?Resident $target = null,
    ): ?array {
        $offer = $request->mutationOffer;

        if ($offer === null) {
            return null;
        }

        return [
            'id' => $offer->id,
            'mutation_type' => $offer->mutation_type,
            'mutation_label' => $offer->mutationLabel(),
            'status' => $offer->status,
            'is_pending' => $offer->isPending(),
            'decided_at' => self::villageDateTime($offer),
            'decided_by_name' => $offer->decided_by_name,
            'decline_reason' => $offer->decline_reason,
            /*
             * Id penduduk hasil mutasi, agar layar dapat menautkan langsung
             * ke datanya — pada SKL inilah satu-satunya jalan menemukan bayi
             * yang baru saja dibuat, karena namanya belum ada di mana pun
             * selain di surat.
             */
            'resident_id' => $offer->mutation?->resident_id,
            /*
             * Surat yang dibatalkan tidak dapat lagi menjadi dasar mutasi
             * (RULE-05). Dikirim agar layar menjelaskan sebabnya alih-alih
             * menampilkan tombol yang pasti ditolak server.
             */
            'letter_cancelled' => $request->letter?->status === Letter::STATUS_CANCELLED,
            'target' => $target === null ? null : [
                'id' => $target->id,
                'full_name' => $target->full_name,
                'nik_masked' => $target->nik_masked,
                'status' => $target->status,
                /*
                 * RULE-02: KK tidak boleh tanpa kepala keluarga. Petugas
                 * diingatkan SEBELUM menekan tombol, karena setelah status
                 * berubah menjadi `meninggal` KK itu langsung tanpa kepala
                 * dan tidak ada apa pun yang memaksa penggantinya ditetapkan
                 * (docs/13 §5.5).
                 */
                'is_head_of_family' => $target->isHeadOfFamily(),
                'family_id' => $target->family_id,
            ],
            /*
             * Pemohon sudah ada di payload pengajuan; yang diperlukan di sini
             * hanyalah apakah KK-nya ada — tanpa KK, bayi tidak punya tempat
             * untuk ditambahkan dan tombolnya tidak boleh menjanjikan apa pun.
             */
            'applicant_has_family' => $request->resident->family_id !== null,
        ];
    }

    /** "15 Oktober 2026, 14.32" pada kalender desa. */
    private static function villageDateTime(LetterMutationOffer $offer): ?string
    {
        return $offer->decided_at === null
            ? null
            : VillageTime::local($offer->decided_at)->locale('id')->translatedFormat('d F Y, H.i');
    }
}
