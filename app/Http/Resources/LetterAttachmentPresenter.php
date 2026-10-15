<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LetterAttachment;
use App\Models\LetterRequest;
use App\Services\Letter\LetterAttachmentService;
use Illuminate\Support\Facades\Storage;

/**
 * Penerjemah lampiran untuk lapis tampilan.
 *
 * `path` dan `disk` TIDAK ikut dikirim: letak berkas di penyimpanan bukan
 * urusan peramban, dan membocorkannya memberi penyerang separuh jalan menuju
 * bucket yang seharusnya tertutup. Yang dikirim hanya tautan ke rute
 * berlingkup, yang memeriksa kewenangan pada setiap permintaan.
 */
final class LetterAttachmentPresenter
{
    /** @return array<string, mixed> */
    public static function one(LetterAttachment $attachment, bool $exists): array
    {
        return [
            'id' => $attachment->id,
            'requirement_index' => $attachment->requirement_index,
            'requirement_label' => $attachment->requirement_label,
            'original_name' => $attachment->original_name,
            'download_name' => $attachment->downloadName(),
            'mime' => $attachment->mime,
            'extension' => $attachment->extension,
            'is_image' => $attachment->isImage(),
            'size_bytes' => $attachment->size_bytes,
            'size_label' => app(LetterAttachmentService::class)->humanSize($attachment->size_bytes),
            'uploaded_by_name' => $attachment->uploaded_by_name,
            'uploaded_at' => $attachment->created_at->toIso8601String(),
            'url' => '/surat/'.$attachment->request_id.'/lampiran/'.$attachment->id,
            /*
             * Berkas yang barisnya ada tetapi isinya tidak lagi di
             * penyimpanan HARUS TERLIHAT (KEP-013).
             *
             * Keadaan ini nyata: disk pengembangan dibersihkan, bucket
             * dipindahkan, berkas dihapus dari luar aplikasi. Tanpa penanda
             * ini, layar menampilkan tautan yang tampak sehat dan baru
             * gagal saat ditekan — dan petugas menyimpulkan sistemnya rusak,
             * bukan berkasnya hilang.
             */
            'tersedia' => $exists,
        ];
    }

    /**
     * Seluruh lampiran satu pengajuan.
     *
     * Keberadaan tiap berkas diperiksa sekali di sini. Pada S3 ini berarti
     * satu panggilan jaringan per lampiran; jumlahnya dibatasi
     * `sid.uploads.max_per_request`, dan harganya dibayar hanya di layar
     * pemeriksaan — bukan di antrean yang memuat puluhan baris.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forRequest(LetterRequest $request): array
    {
        $disk = app(LetterAttachmentService::class)->diskName();

        return $request->attachments
            ->map(fn (LetterAttachment $a): array => self::one(
                $a,
                Storage::disk($a->disk === '' ? $disk : $a->disk)->exists($a->path),
            ))
            ->values()
            ->all();
    }
}
