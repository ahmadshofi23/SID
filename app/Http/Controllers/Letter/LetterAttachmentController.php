<?php

declare(strict_types=1);

namespace App\Http\Controllers\Letter;

use App\Http\Controllers\Controller;
use App\Models\LetterAttachment;
use App\Models\LetterRequest;
use App\Repositories\LetterRequestRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Letter\LetterAttachmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lampiran syarat pengajuan surat — Sprint 5, KEP-013 §1.
 *
 * Tanpa konstruktor (KEP-009).
 *
 * SETIAP aksi di sini bermula dari LetterRequestRepository::findOrFail():
 * lampiran tidak punya lingkupnya sendiri, ia mewarisi lingkup pengajuannya.
 * Mengambil LetterAttachment langsung lewat id-nya akan melewati seluruh
 * pembatasan wilayah dan keluarga sekaligus.
 */
final class LetterAttachmentController extends Controller
{
    /** Unggah satu berkas ke pengajuan yang sudah ada. */
    public function store(
        Request $request,
        string $id,
        LetterRequestRepository $requests,
        LetterAttachmentService $attachments,
    ): RedirectResponse {
        $this->authorize('surat.ubah');

        $letterRequest = $requests->findOrFail($id);
        $letterRequest->load('letterType');

        $validated = $request->validate([
            // `file` saja, TANPA `mimes:`/`image:` — tipe nyata diperiksa
            // FileSignature, lihat alasannya di kelas itu. Batas `max:` di
            // sini hanya agar berkas raksasa ditolak sebelum dibaca isinya.
            'berkas' => ['required', 'file', 'max:'.(int) ($attachments->maxBytes() / 1024)],
            'requirement_index' => ['nullable', 'integer', 'min:0'],
        ], [
            'berkas.max' => 'Ukuran berkas melebihi batas '
                .$attachments->humanSize($attachments->maxBytes()).' per berkas.',
        ]);

        /*
         * Dikonversi ke int secara eksplisit.
         *
         * Unggahan berkas dikirim sebagai multipart/form-data, dan di sana
         * SETIAP nilai sampai sebagai string — termasuk "2". Aturan
         * `integer` Laravel meluluskan string numerik tanpa mengubah
         * tipenya, sehingga nilainya tetap string saat diteruskan. Uji yang
         * mengirim array PHP biasa tidak akan pernah menangkap ini: di sana
         * angkanya memang sudah int.
         */
        $index = self::requirementIndex(
            $letterRequest,
            isset($validated['requirement_index']) ? (int) $validated['requirement_index'] : null,
        );

        /** @var UploadedFile $berkas */
        $berkas = $request->file('berkas');

        $attachments->store($letterRequest, $berkas, $index, 'berkas');

        return back()->with('success', 'Berkas syarat diunggah.');
    }

    /**
     * Pratinjau atau unduhan satu berkas.
     *
     * TIDAK memakai URL pra-tanda-tangan S3 (menyimpang dari docs/04 §6,
     * dengan sengaja). URL berumur pendek tetap dapat diteruskan ke siapa
     * pun selama masa berlakunya, dan lingkup wilayah tidak lagi diperiksa
     * setelah ia terbit. Berkas syarat memuat fotokopi KTP; pemeriksaannya
     * dilakukan pada setiap permintaan, sama seperti unduhan PDF surat.
     */
    public function show(
        Request $request,
        string $id,
        string $attachmentId,
        LetterRequestRepository $requests,
        LetterAttachmentService $attachments,
        AuditLogger $audit,
    ): StreamedResponse {
        $this->authorize('surat.lihat');

        $letterRequest = $requests->findOrFail($id);
        $attachment = self::attachmentOrFail($letterRequest, $attachmentId);

        $disk = $attachments->disk();

        if (! $disk->exists($attachment->path)) {
            abort(410, 'Berkas lampiran ini tidak ditemukan di penyimpanan. '
                .'Minta pemohon mengunggahnya ulang.');
        }

        $audit->log('letter_request.attachment_viewed', $letterRequest, context: [
            'tiket' => $letterRequest->ticket_number,
            'syarat' => $attachment->requirement_label,
            'nama_berkas' => $attachment->original_name,
        ]);

        $name = $attachment->downloadName();

        /*
         * Pratinjau di dalam peramban hanya untuk gambar.
         *
         * PDF selalu DIUNDUH, tidak ditampilkan inline: menampilkan berkas
         * unggahan pihak lain pada origin aplikasi membuat apa pun di dalam
         * berkas itu berjalan dengan sesi petugas. Content-Security-Policy
         * aplikasi memang menutup sebagian besar jalan itu, tetapi lapisan
         * ini tidak perlu bergantung padanya.
         */
        if ($request->boolean('pratinjau') && $attachment->isImage()) {
            return $disk->response($attachment->path, $name, [
                'Content-Type' => $attachment->mime,
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
            ]);
        }

        return $disk->download($attachment->path, $name, [
            'Content-Type' => $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(
        string $id,
        string $attachmentId,
        LetterRequestRepository $requests,
        LetterAttachmentService $attachments,
    ): RedirectResponse {
        $this->authorize('surat.ubah');

        $letterRequest = $requests->findOrFail($id);
        $attachment = self::attachmentOrFail($letterRequest, $attachmentId);

        $attachments->delete($letterRequest, $attachment);

        return back()->with('success', 'Berkas lampiran dihapus.');
    }

    /**
     * Lampiran MILIK pengajuan ini, atau 404.
     *
     * Dicari lewat relasi, bukan LetterAttachment::findOrFail(): id lampiran
     * dari pengajuan lain — berapa pun lingkup wilayahnya — tidak boleh
     * memberi hasil hanya karena id pengajuan pada URL kebetulan sah.
     */
    private static function attachmentOrFail(LetterRequest $request, string $attachmentId): LetterAttachment
    {
        $attachment = $request->attachments()->whereKey($attachmentId)->first();

        if ($attachment === null) {
            abort(404);
        }

        return $attachment;
    }

    /**
     * Indeks syarat yang sah, atau null bila berkasnya tambahan.
     *
     * Indeks di luar daftar syarat dibuang alih-alih ditolak: daftar syarat
     * dapat menyusut setelah formulir terbuka di layar petugas, dan
     * menolak unggahannya hanya akan membuat berkas yang sudah di tangan
     * tidak dapat dimasukkan sama sekali.
     */
    private static function requirementIndex(LetterRequest $request, ?int $index): ?int
    {
        if ($index === null) {
            return null;
        }

        return isset($request->letterType->requirements[$index]) ? $index : null;
    }
}
