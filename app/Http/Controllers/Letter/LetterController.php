<?php

declare(strict_types=1);

namespace App\Http\Controllers\Letter;

use App\Http\Controllers\Controller;
use App\Http\Resources\LetterRequestPresenter;
use App\Models\Letter;
use App\Models\LetterType;
use App\Models\VillageProfile;
use App\Repositories\LetterRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Letter\LetterQrCode;
use App\Services\Letter\LetterRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Arsip & cetak surat terbit — FR-SRT-11, FR-SRT-12.
 *
 * Tanpa konstruktor (KEP-009).
 */
final class LetterController extends Controller
{
    public function index(Request $request, LetterRepository $letters): Response
    {
        $this->authorize('surat.lihat');

        $filters = [
            'term' => $request->string('term')->toString(),
            'letter_type_id' => $request->string('letter_type_id')->toString(),
            'status' => $request->string('status')->toString(),
            'from' => $request->string('from')->toString(),
            'to' => $request->string('to')->toString(),
        ];

        $page = $letters->archive($filters);

        return Inertia::render('Surat/Arsip/Index', [
            'letters' => [
                'data' => array_map(
                    fn (Letter $letter): array => [
                        ...LetterRequestPresenter::letter($letter),
                        'letter_type' => [
                            'code' => $letter->letterType->code,
                            'name' => $letter->letterType->name,
                        ],
                        'ticket_number' => $letter->request->ticket_number,
                        'resident_name' => $letter->request->resident->full_name,
                        'resident_nik' => $letter->request->resident->nik_masked,
                        'request_id' => $letter->request_id,
                    ],
                    $page->items(),
                ),
                'prev_url' => $page->previousPageUrl(),
                'next_url' => $page->nextPageUrl(),
                'count' => count($page->items()),
            ],
            'filters' => $filters,
            'types' => LetterType::query()->orderBy('sort_order')
                ->get(['id', 'code', 'name'])->all(),
        ]);
    }

    /**
     * Pratinjau & cetak — FR-SRT-11.
     *
     * Menampilkan `rendered_html` YANG DISIMPAN, bukan merender ulang dari
     * template (ADR-003). Template berubah seiring waktu; surat yang sudah
     * terbit harus tampil sama persis seperti saat ditandatangani, walau
     * templatnya sudah lima kali disunting sejak itu.
     */
    public function print(
        string $id,
        LetterRepository $letters,
        AuditLogger $audit,
        LetterQrCode $qr,
    ): Response {
        $this->authorize('surat.lihat');

        $letter = $letters->findOrFail($id);
        $letter->load(['letterType', 'request.resident']);

        /*
         * Penghitung cetak dinaikkan dengan increment() basis data, bukan
         * `$letter->print_count++` lalu save(): dua petugas yang mencetak
         * bersamaan akan saling menimpa dan hitungannya meleset.
         *
         * increment() juga melewati guard `updating` di model Letter —
         * itu disengaja, print_count termasuk MUTABLE_AFTER_ISSUE.
         */
        $letter->increment('print_count');

        $audit->log('letter.printed', $letter, context: [
            'nomor_surat' => $letter->letter_number,
            'cetakan_ke' => $letter->print_count,
        ]);

        $village = VillageProfile::current();

        return Inertia::render('Surat/Arsip/Cetak', [
            'letter' => [
                ...LetterRequestPresenter::letter($letter),
                'title_on_letter' => $letter->letterType->title_on_letter,
                'rendered_html' => $letter->rendered_html,
                /*
                 * QR dibuat DI SERVER, bukan diambil peramban dari layanan
                 * pembuat QR pihak ketiga seperti pada Sprint 4: layanan itu
                 * menerima kode verifikasi setiap surat desa, dan surat
                 * berhenti dapat dicetak ketika layanannya mati. Gambar yang
                 * sama dipakai di PDF (LetterPdfDocument).
                 */
                'qr_svg' => $qr->svg($letter->verification_code),
                /*
                 * `snapshot` TIDAK dikirim ke peramban.
                 *
                 * Seluruh isi surat sudah ada di rendered_html; snapshot
                 * hanya cadangan terstruktur untuk audit dan penelusuran.
                 * Mengirimnya berarti data pribadi pemohon melintas dua kali
                 * tanpa dipakai sekali pun.
                 */
            ],
            'village' => [
                'name' => $village->name,
                'district' => $village->district,
                'regency' => $village->regency,
                'province' => $village->province,
                'address' => $village->address,
                'postal_code' => $village->postal_code,
                'phone' => $village->phone,
                'email' => $village->email,
                'website' => $village->website,
                'logo_path' => $village->logo_path,
            ],
        ]);
    }

    /** Pembatalan surat terbit — FR-SRT-12, RULE-05. */
    public function cancel(
        Request $request,
        string $id,
        LetterRepository $letters,
        LetterRequestService $service,
    ): RedirectResponse {
        $this->authorize('surat.batalkan');

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $letter = $service->cancelLetter(
            $letters->findOrFail($id),
            $validated['reason'],
            $request->user()?->id,
        );

        return back()->with(
            'success',
            'Surat '.$letter->letter_number.' dibatalkan. Nomornya TIDAK dipakai ulang '
            .'— terbitkan surat pengganti bila diperlukan.',
        );
    }
}
