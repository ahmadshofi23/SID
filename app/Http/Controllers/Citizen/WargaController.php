<?php

declare(strict_types=1);

namespace App\Http\Controllers\Citizen;

use App\Auth\AccessScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\LetterRequestPresenter;
use App\Models\LetterAttachment;
use App\Models\LetterRequest;
use App\Models\LetterType;
use App\Models\Resident;
use App\Repositories\LetterRequestRepository;
use App\Repositories\ResidentRepository;
use App\Services\Letter\DynamicFormValidator;
use App\Services\Letter\LetterAttachmentService;
use App\Services\Letter\LetterRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Portal warga — UC-05, FR-SRT-04, BR-10, docs/07 §2.2.
 *
 * SELURUH LAYAR DI SINI MEMBACA LEWAT REPOSITORY BERLINGKUP, dan itulah
 * satu-satunya hal yang membatasi warga pada dirinya sendiri dan satu KK
 * (RULE-11). Tidak ada satu pun pemeriksaan "apakah pengajuan ini milik
 * saya" yang ditulis tangan di kelas ini — pengajuan milik keluarga lain
 * tidak terlihat, sehingga menjadi 404 dengan sendirinya (docs/06 §4).
 *
 * Jalur loket TIDAK digantikan. Warga tanpa akun tetap dapat datang ke
 * kantor desa, dan `surat/lacak` tetap menjadi "cek resi" tanpa login
 * (KEP-014 §5) — daftar di sini adalah jalur kedua, bukan penggantinya.
 *
 * Tanpa konstruktor (KEP-009).
 */
final class WargaController extends Controller
{
    public function beranda(
        LetterRequestRepository $requests,
        ResidentRepository $residents,
        AccessScope $scope,
    ): Response {
        $aktif = $requests->queue(['status' => 'terbuka'], perPage: 5);

        return Inertia::render('Warga/Beranda', [
            'aktif' => array_map(
                LetterRequestPresenter::forCitizen(...),
                $aktif->items(),
            ),
            'diri' => $this->selfPayload($residents, $scope),
            'jumlah_keluarga' => $scope->familyId === null
                ? 0
                : count($residents->membersOfFamily($scope->familyId)),
        ]);
    }

    /** Riwayat seluruh pengajuan milik keluarga — FR-SRT-13. */
    public function pengajuan(Request $request, LetterRequestRepository $requests): Response
    {
        $page = $requests->queue([
            'status' => $request->string('status')->toString(),
        ], perPage: 20);

        return Inertia::render('Warga/Pengajuan/Index', [
            'pengajuan' => [
                'data' => array_map(LetterRequestPresenter::forCitizen(...), $page->items()),
                'prev_url' => $page->previousPageUrl(),
                'next_url' => $page->nextPageUrl(),
                'count' => count($page->items()),
            ],
            'filters' => ['status' => $request->string('status')->toString()],
        ]);
    }

    public function pengajuanDetail(string $id, LetterRequestRepository $requests): Response
    {
        // findOrFail() repository: di luar lingkup keluarga -> 404, bukan 403.
        $letterRequest = $requests->findOrFail($id);
        $letterRequest->load(['letterType', 'resident', 'logs', 'letter', 'attachments']);

        return Inertia::render('Warga/Pengajuan/Detail', [
            'pengajuan' => LetterRequestPresenter::forCitizen($letterRequest),
            'syarat' => $letterRequest->letterType->requirements,
            /*
             * Hanya PENANDA ADA-TIDAKNYA berkas, bukan tautan unduhnya.
             *
             * Warga sudah memegang berkas yang ia unggah sendiri, sementara
             * membuka rute unduh di layar ini menambah satu jalur lagi ke
             * fotokopi KTP yang harus ikut diperiksa setiap kali aturan
             * aksesnya berubah. Petugas yang memeriksa tetap punya jalurnya
             * sendiri di layar antrean.
             */
            'lampiran' => $letterRequest->attachments
                ->map(fn (LetterAttachment $a): array => [
                    'id' => $a->id,
                    'label' => $a->requirement_label,
                    'original_name' => $a->original_name,
                    'requirement_index' => $a->requirement_index,
                ])->values()->all(),
        ]);
    }

    /** Data diri & keluarga, HANYA BACA — docs/07 §2.2. */
    public function keluarga(ResidentRepository $residents, AccessScope $scope): Response
    {
        $anggota = $scope->familyId === null
            ? []
            : $residents->membersOfFamily($scope->familyId)
                ->map(fn (Resident $r): array => [
                    'id' => $r->id,
                    'full_name' => $r->full_name,
                    // NIK TER-MASKING, juga bagi keluarga sendiri (RULE-07):
                    // layar ini dibuka di ponsel yang kadang dipinjamkan, dan
                    // tidak ada keperluan yang menuntut enam belas digit.
                    'nik_masked' => $r->nik_masked,
                    'family_relation_code' => $r->family_relation_code,
                    'gender' => $r->gender,
                    'birth_date' => $r->birth_date->toDateString(),
                    'status' => $r->status,
                    'is_me' => $r->id === $scope->residentId,
                ])->values()->all();

        return Inertia::render('Warga/Keluarga', [
            'anggota' => $anggota,
            'diri' => $this->selfPayload($residents, $scope),
        ]);
    }

    /* ========================= Pengajuan daring ========================== */

    public function ajukan(
        Request $request,
        DynamicFormValidator $forms,
        LetterAttachmentService $attachments,
        ResidentRepository $residents,
        LetterRequestRepository $requests,
        AccessScope $scope,
    ): Response {
        /*
         * Hanya jenis surat yang DIBUKA untuk pengajuan daring (`is_public`).
         * Sebagian surat menuntut pemeriksaan lapangan atau berkas asli di
         * meja petugas; menampilkannya di sini berarti menjanjikan layanan
         * yang akan ditolak setelah warga mengisi seluruh formulir.
         */
        $types = LetterType::query()
            ->active()
            ->where('is_public', true)
            ->orderBy('sort_order')
            ->get();

        $selected = $types->firstWhere('code', $request->string('jenis')->toString())
            ?? $types->first();

        $anggota = $scope->familyId === null
            ? collect()
            : $residents->membersOfFamily($scope->familyId);

        return Inertia::render('Warga/Ajukan', [
            'types' => $types->map(fn (LetterType $t): array => [
                'id' => $t->id,
                'code' => $t->code,
                'name' => $t->name,
                'description' => $t->description,
                'sla_days' => $t->sla_days,
                'fee' => (float) $t->fee,
                'requirements' => $t->requirements,
                'fields' => $forms->fields($t),
                'warning_note' => $t->warning_note,
            ])->all(),
            'selected_type_id' => $selected?->id,
            'upload' => $attachments->constraints(),

            /*
             * Pemohon: diri sendiri atau anggota satu KK (UC-05 langkah 4,
             * RULE-11). Daftarnya datang dari repository berlingkup, jadi
             * tidak ada orang di luar keluarga yang dapat muncul di sini —
             * dan tidak ada pula yang dapat dikirim lewat permintaan buatan
             * tangan, karena LetterRequestService mencari pemohon lewat
             * repository yang sama.
             */
            'pemohon' => $anggota->map(fn (Resident $r): array => [
                'id' => $r->id,
                'full_name' => $r->full_name,
                'nik_masked' => $r->nik_masked,
                'birth_date' => $r->birth_date->toDateString(),
                'family_relation_code' => $r->family_relation_code,
                'status' => $r->status,
                'is_me' => $r->id === $scope->residentId,
            ])->values()->all(),

            /*
             * Jenis surat yang pemohonnya masih punya pengajuan berjalan.
             * Dikirim ke layar agar peringatannya muncul SEBELUM warga
             * mengisi formulir, bukan setelah ia menekan Kirim (UC-05 7b).
             */
            'berjalan' => $this->openRequestsByType($requests, $anggota->pluck('id')->all()),
        ]);
    }

    public function kirim(
        Request $request,
        AccessScope $scope,
        LetterRequestService $service,
        LetterAttachmentService $attachments,
    ): RedirectResponse {
        $validated = $request->validate([
            'letter_type_id' => ['required', 'uuid', 'exists:letter_types,id'],
            'resident_id' => ['required', 'uuid'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'form' => ['array'],
            'konfirmasi_kembar' => ['nullable', 'boolean'],
        ]);

        $type = LetterType::query()
            ->active()
            ->where('is_public', true)
            ->findOrFail($validated['letter_type_id']);

        $berkas = $attachments->requirementFilesFrom($request);

        /*
         * SYARAT WAJIB BENAR-BENAR WAJIB DI JALUR DARING (BR-10).
         *
         * Di loket, berkas boleh menyusul karena petugas memegang
         * fotokopinya. Warga yang mengajukan dari rumah tidak punya petugas
         * yang dapat menyusulkan apa pun, dan pengajuan tanpa syarat hanya
         * akan dikembalikan sebagai permintaan revisi beberapa hari kemudian.
         * Kekurangannya disebut SATU PER SATU, bukan "lengkapi syaratnya".
         */
        $kurang = $attachments->missingRequiredRequirements($type, $berkas);

        if ($kurang !== []) {
            return back()->withErrors($kurang)->withInput();
        }

        $attachments->assertAllValid($berkas);

        $letterRequest = $service->create(
            scope: $scope,
            type: $type,
            residentId: $validated['resident_id'],
            purpose: $validated['purpose'] ?? null,
            formInput: $request->input('form', []),
            channel: 'online',
            allowDuplicate: (bool) ($validated['konfirmasi_kembar'] ?? false),
        );

        $letterRequest->setRelation('letterType', $type);

        foreach ($berkas as $field => $file) {
            $attachments->store(
                $letterRequest,
                $file,
                $attachments->requirementIndex($field, $type),
                $field,
            );
        }

        return to_route('warga.pengajuan.detail', $letterRequest->id)
            ->with('status', 'Pengajuan terkirim. Nomor tiket Anda: '
                .$letterRequest->ticket_number.'.');
    }

    /** Halaman buntu untuk akun warga yang belum dapat memakai layanan mandiri. */
    public function belumAktif(): Response
    {
        return Inertia::render('Warga/BelumAktif');
    }

    /* ================================ Bantu =============================== */

    /**
     * @param  list<string>  $residentIds
     * @return array<string, string> letter_type_id => nomor tiket berjalan
     */
    private function openRequestsByType(LetterRequestRepository $requests, array $residentIds): array
    {
        $berjalan = [];

        foreach ($requests->queue(['status' => 'terbuka'], perPage: 50)->items() as $item) {
            /** @var LetterRequest $item */
            if (in_array($item->resident_id, $residentIds, true)) {
                $berjalan[$item->letter_type_id.'|'.$item->resident_id] = $item->ticket_number;
            }
        }

        return $berjalan;
    }

    /** @return array<string, mixed>|null */
    private function selfPayload(ResidentRepository $residents, AccessScope $scope): ?array
    {
        $me = $scope->residentId === null ? null : $residents->find($scope->residentId);

        return $me === null ? null : [
            'id' => $me->id,
            'full_name' => $me->full_name,
            'nik_masked' => $me->nik_masked,
            'birth_date' => $me->birth_date->toDateString(),
        ];
    }
}
