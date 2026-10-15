<?php

declare(strict_types=1);

namespace App\Http\Controllers\Letter;

use App\Auth\AccessScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\LetterRequestPresenter;
use App\Models\LetterRequest;
use App\Models\LetterType;
use App\Models\Official;
use App\Repositories\LetterRequestRepository;
use App\Services\Letter\DynamicFormValidator;
use App\Services\Letter\LetterNumberService;
use App\Services\Letter\LetterRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pengajuan & pemrosesan surat — FR-SRT-03 s.d. FR-SRT-12.
 *
 * Tanpa konstruktor (KEP-009): AccessScope dan repository berlingkup WAJIB
 * lewat injeksi method. Illuminate\Routing\Route menyimpan instance
 * controller selama proses hidup, sehingga lingkup pengguna PERTAMA akan
 * terpakai untuk semua pengguna berikutnya.
 */
final class LetterRequestController extends Controller
{
    public function index(
        Request $request,
        LetterRequestRepository $requests,
    ): Response {
        $this->authorize('surat.lihat');

        $filters = [
            'status' => $request->string('status')->toString() ?: 'terbuka',
            'letter_type_id' => $request->string('letter_type_id')->toString(),
            'ticket' => $request->string('ticket')->toString(),
            'overdue' => $request->boolean('overdue'),
        ];

        $page = $requests->queue($filters);

        return Inertia::render('Surat/Pengajuan/Index', [
            'requests' => [
                'data' => array_map(LetterRequestPresenter::row(...), $page->items()),
                'prev_url' => $page->previousPageUrl(),
                'next_url' => $page->nextPageUrl(),
                'count' => count($page->items()),
            ],
            'filters' => $filters,
            'statuses' => LetterRequest::statusLabels(),
            'summary' => [
                'by_status' => $requests->countByStatus(),
                'overdue' => $requests->countOverdue(),
            ],
            'types' => LetterType::query()->orderBy('sort_order')
                ->get(['id', 'code', 'name'])->all(),
        ]);
    }

    public function show(
        string $id,
        LetterRequestRepository $requests,
        DynamicFormValidator $forms,
        LetterNumberService $numbers,
    ): Response {
        $this->authorize('surat.lihat');

        $request = $requests->findOrFail($id);
        $request->load(['letterType', 'resident.family', 'logs', 'letter']);

        return Inertia::render('Surat/Pengajuan/Detail', [
            'request' => LetterRequestPresenter::detail($request),
            'form_fields' => $forms->fields($request->letterType),
            /*
             * Nomor berikutnya ditampilkan agar petugas tahu nomor berapa
             * yang akan terbit. Ini PERKIRAAN: nomor sebenarnya ditetapkan
             * saat persetujuan, dan dapat berbeda bila petugas lain
             * menerbitkan surat lebih dulu.
             */
            'next_number_preview' => $request->status === LetterRequest::STATUS_VERIFIED
                ? $numbers->preview($request->letterType)
                : null,
            'signers' => Official::query()
                ->where('is_active', true)
                ->where('can_sign_letter', true)
                ->orderBy('sort_order')
                ->get(['id', 'name', 'position'])->all(),
        ]);
    }

    /** Formulir pengajuan di loket — FR-SRT-05. */
    public function create(Request $request, DynamicFormValidator $forms): Response
    {
        $this->authorize('surat.ajukan');

        $types = LetterType::query()->active()->orderBy('sort_order')->get();
        $selected = $types->firstWhere('code', $request->string('jenis')->toString())
            ?? $types->first();

        return Inertia::render('Surat/Pengajuan/Form', [
            'types' => $types->map(fn (LetterType $t): array => [
                'id' => $t->id,
                'code' => $t->code,
                'name' => $t->name,
                'sla_days' => $t->sla_days,
                'requirements' => $t->requirements,
                'fields' => $forms->fields($t),
                'warning_note' => $t->warning_note,
            ])->all(),
            'selected_type_id' => $selected?->id,
        ]);
    }

    public function store(
        Request $request,
        AccessScope $scope,
        LetterRequestService $service,
    ): RedirectResponse {
        $this->authorize('surat.ajukan');

        $validated = $request->validate([
            'letter_type_id' => ['required', 'uuid', 'exists:letter_types,id'],
            'resident_id' => ['required', 'uuid'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'form' => ['array'],
        ]);

        $type = LetterType::query()->findOrFail($validated['letter_type_id']);

        $letterRequest = $service->create(
            scope: $scope,
            type: $type,
            residentId: $validated['resident_id'],
            purpose: $validated['purpose'] ?? null,
            formInput: $request->input('form', []),
            channel: 'walk_in',
        );

        return to_route('surat.show', $letterRequest->id)
            ->with('success', 'Pengajuan dibuat dengan tiket '.$letterRequest->ticket_number.'.');
    }

    /** Perbarui isian setelah diminta revisi — FR-SRT-09. */
    public function update(
        Request $request,
        string $id,
        LetterRequestRepository $requests,
        DynamicFormValidator $forms,
        LetterRequestService $service,
    ): RedirectResponse {
        $this->authorize('surat.ubah');

        $letterRequest = $requests->findOrFail($id);

        /*
         * Hanya draf dan pengajuan yang diminta revisi boleh disunting.
         *
         * Pengajuan yang sedang diperiksa TIDAK boleh berubah di bawah
         * tangan pemeriksanya: ia dapat menyetujui isi yang sudah berbeda
         * dari yang dibacanya.
         */
        if (! in_array($letterRequest->status, [
            LetterRequest::STATUS_DRAFT,
            LetterRequest::STATUS_NEEDS_REVISION,
        ], true)) {
            abort(409, 'Pengajuan yang sedang diproses tidak dapat disunting.');
        }

        $request->validate([
            'purpose' => ['nullable', 'string', 'max:255'],
            'form' => ['array'],
        ]);

        $letterRequest->form_data = $forms->validate(
            $letterRequest->letterType,
            $request->input('form', []),
        );
        $letterRequest->purpose = $request->input('purpose');
        $letterRequest->save();

        $service->submit($letterRequest, 'Diajukan ulang setelah revisi');

        return back()->with('success', 'Pengajuan diperbarui dan diajukan ulang.');
    }

    public function verify(
        Request $request,
        string $id,
        AccessScope $scope,
        LetterRequestRepository $requests,
        LetterRequestService $service,
    ): RedirectResponse {
        $this->authorize('surat.verifikasi');

        $letterRequest = $requests->findOrFail($id);

        $service->verify($scope, $letterRequest, $request->input('note'));

        return back()->with('success', 'Pengajuan diverifikasi.');
    }

    public function requestRevision(
        Request $request,
        string $id,
        AccessScope $scope,
        LetterRequestRepository $requests,
        LetterRequestService $service,
    ): RedirectResponse {
        $this->authorize('surat.verifikasi');

        $validated = $request->validate([
            'note' => ['required', 'string', 'max:1000'],
        ]);

        $service->requestRevision($scope, $requests->findOrFail($id), $validated['note']);

        return back()->with('success', 'Pengajuan dikembalikan untuk revisi.');
    }

    public function reject(
        Request $request,
        string $id,
        AccessScope $scope,
        LetterRequestRepository $requests,
        LetterRequestService $service,
    ): RedirectResponse {
        $this->authorize('surat.verifikasi');

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $service->reject($scope, $requests->findOrFail($id), $validated['reason']);

        return back()->with('success', 'Pengajuan ditolak.');
    }

    /** Persetujuan & penerbitan — di sinilah nomor surat terbit (RULE-04). */
    public function issue(
        Request $request,
        string $id,
        AccessScope $scope,
        LetterRequestRepository $requests,
        LetterRequestService $service,
    ): RedirectResponse {
        $this->authorize('surat.setujui');

        $validated = $request->validate([
            'signer_official_id' => ['nullable', 'uuid', 'exists:officials,id'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $signer = isset($validated['signer_official_id'])
            ? Official::query()->findOrFail($validated['signer_official_id'])
            : null;

        if ($signer !== null && ! $signer->can_sign_letter) {
            return back()->withErrors([
                'signer_official_id' => $signer->name.' tidak ditetapkan sebagai '
                    .'penanda tangan surat. Atur di Pengaturan › Perangkat Desa.',
            ]);
        }

        $letter = $service->issue(
            $scope,
            $requests->findOrFail($id),
            $signer,
            $validated['note'] ?? null,
        );

        return back()->with('success', 'Surat terbit dengan nomor '.$letter->letter_number.'.');
    }

    public function cancel(
        Request $request,
        string $id,
        LetterRequestRepository $requests,
        LetterRequestService $service,
    ): RedirectResponse {
        $this->authorize('surat.ubah');

        $service->cancel($requests->findOrFail($id), $request->input('reason'));

        return back()->with('success', 'Pengajuan dibatalkan.');
    }
}
