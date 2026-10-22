<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Complaint;
use App\Models\ComplaintAction;
use App\Support\ReferenceData;
use App\Support\VillageTime;
use Illuminate\Support\Carbon;

/**
 * Penerjemah pengaduan untuk lapis tampilan.
 *
 * Alasan keberadaannya sama dengan LetterRequestPresenter — payload yang
 * disusun ad-hoc per controller cepat sekali berbeda-beda — tetapi pada
 * pengaduan ia menanggung satu beban tambahan yang lebih berat:
 *
 * ADA DUA HAL YANG TIDAK BOLEH LEWAT KE PERAMBAN, dan keduanya berbeda
 * menurut siapa yang membaca:
 *
 *   1. `tracking_code`. Ia rahasia pembuka pengaduan. Ia hanya boleh keluar
 *      SATU KALI — pada halaman tanda terima, tepat setelah pengaduan
 *      dikirim — dan karena itu tidak pernah disusun di sini (lihat
 *      `receipt()`, yang menerimanya sebagai argumen terpisah agar
 *      pemanggilnya harus menyebutnya secara sadar).
 *
 *   2. Identitas pelapor. Pada pengaduan anonim ia memang tidak ada di basis
 *      data (RULE-09). Tetapi pada pengaduan BERNAMA, identitas itu tidak
 *      boleh ikut ke muatan yang dibaca pelapornya sendiri maupun ke
 *      ringkasan publik — nomor teleponnya tidak menambah apa pun di sana,
 *      dan sekali ia masuk payload ia akan tersalin ke mana-mana.
 */
final class ComplaintPresenter
{
    /**
     * Tanda terima — SATU-SATUNYA tempat kode pelacakan keluar.
     *
     * Kodenya diterima sebagai argumen, bukan dibaca dari $complaint, supaya
     * tidak ada pemanggil yang mengirimkannya tanpa sengaja: memilih metode
     * ini sudah merupakan pernyataan "halaman ini memang halaman tanda
     * terima". Dan ia tidak pernah dapat dibuka kembali — kode yang hilang
     * berarti pelapor anonim kehilangan jalur ke pengaduannya, dan itulah
     * harga keanoniman yang harus dikatakan terang-terangan di layar.
     *
     * @return array<string, mixed>
     */
    public static function receipt(Complaint $complaint, string $trackingCode): array
    {
        return [
            'ticket_number' => $complaint->ticket_number,
            'tracking_code' => $trackingCode,
            'is_anonymous' => $complaint->is_anonymous,
            'title' => $complaint->title,
            'triage_due_at' => self::villageDate($complaint->triage_due_at),
        ];
    }

    /**
     * Pelacakan oleh pelapor dengan kode — FR-PGD-02.
     *
     * Dibaca tanpa otentikasi, jadi isinya paling sempit: status, tenggat,
     * dan tindak lanjut yang memang ditandai terlihat pelapor. Tanpa identitas
     * siapa pun, dan tanpa nama petugas — pelapor tidak perlu tahu siapa yang
     * menangani, sementara menyebutnya pada pengaduan tentang perangkat desa
     * justru dapat membahayakan orang.
     *
     * @return array<string, mixed>
     */
    public static function tracking(Complaint $complaint): array
    {
        return [
            'ticket_number' => $complaint->ticket_number,
            'status' => $complaint->status,
            'status_label' => Complaint::statusLabels()[$complaint->status] ?? $complaint->status,
            'category' => self::categoryLabel($complaint),
            'title' => $complaint->title,
            'is_open' => $complaint->isOpen(),
            'is_overdue' => $complaint->isOverdue(),
            /*
             * Tanggal yang sudah DIFORMAT pada kalender desa, bukan ISO:
             * halaman ini dibaca warga, dan tanggal mentah akan diformat ulang
             * di peramban dengan zona waktu perangkatnya sendiri — "selesai
             * 17 Okt" bisa terbaca "16 Okt" pada ponsel yang zonanya belum
             * diatur.
             */
            'created_at' => self::villageDateTime($complaint->created_at),
            'due_at' => self::villageDate($complaint->activeDueAt()),
            'resolved_at' => self::villageDateTime($complaint->resolved_at),
            'resolution' => $complaint->resolution,
            'timeline' => array_map(
                self::step(...),
                $complaint->actions
                    ->where('is_visible_to_reporter', true)
                    ->sortBy('created_at')
                    ->values()
                    ->all(),
            ),
        ];
    }

    /**
     * Pengaduan milik akun warga sendiri — portal warga.
     *
     * Berbeda dari `tracking()` hanya pada `id`, yang dipakai menautkan ke
     * halaman detail. Lokasi dan kategori ikut karena pelapor memang
     * menuliskannya sendiri; identitas tetap tidak ikut.
     *
     * @return array<string, mixed>
     */
    public static function forCitizen(Complaint $complaint): array
    {
        return [
            ...self::tracking($complaint),
            'id' => $complaint->id,
            'location_text' => $complaint->location_text,
        ];
    }

    /**
     * Ringkasan untuk portal publik — FR-PGD-07.
     *
     * Yang keluar hanya kategori, judul, dan hasil penanganannya. TIDAK ada
     * lokasi, TIDAK ada titik koordinat, dan tentu tidak ada pelapor:
     * "jalan rusak di depan rumah Pak Sumadi" yang terbit di portal desa
     * adalah pengaduan yang menunjuk satu rumah tertentu, dan itu bukan
     * keterbukaan informasi melainkan penyingkapan.
     *
     * @return array<string, mixed>
     */
    public static function publicSummary(Complaint $complaint): array
    {
        return [
            'ticket_number' => $complaint->ticket_number,
            'category' => self::categoryLabel($complaint),
            'title' => $complaint->title,
            'resolution' => $complaint->resolution,
            'resolved_at' => self::villageDate($complaint->resolved_at),
        ];
    }

    /** @return array<string, mixed> */
    private static function step(ComplaintAction $action): array
    {
        $labels = Complaint::statusLabels();

        return [
            'id' => $action->id,
            'to_label' => $action->to_status === null
                ? null
                : ($labels[$action->to_status] ?? $action->to_status),
            'note' => $action->note,
            'at' => self::villageDateTime($action->created_at),
        ];
    }

    private static function categoryLabel(Complaint $complaint): string
    {
        return ReferenceData::get('complaint_categories')[$complaint->category_code]
            ?? $complaint->category_code;
    }

    /** "15 Oktober 2026, 14.32" pada kalender desa. */
    private static function villageDateTime(?Carbon $moment): ?string
    {
        return $moment === null
            ? null
            : VillageTime::local($moment)->locale('id')->translatedFormat('d F Y, H.i');
    }

    /** "15 Oktober 2026" pada kalender desa. */
    private static function villageDate(?Carbon $moment): ?string
    {
        return $moment === null
            ? null
            : VillageTime::local($moment)->locale('id')->translatedFormat('d F Y');
    }
}
