<?php

declare(strict_types=1);

namespace App\Http\Controllers\Population;

use App\Http\Controllers\Controller;
use App\Http\Requests\Population\StoreFamilyRequest;
use App\Http\Requests\Population\UpdateFamilyRequest;
use App\Http\Resources\HistoryPresenter;
use App\Http\Resources\ResidentPresenter;
use App\Repositories\FamilyRepository;
use App\Repositories\ResidentRepository;
use App\Services\Population\ChangeHistoryService;
use App\Services\Population\FamilyService;
use App\Services\Population\RegionService;
use App\Support\ReferenceData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class FamilyController extends Controller
{
    /*
     * Tanpa konstruktor — lihat penjelasan di ResidentController. Singkatnya:
     * Route menyimpan instance controller selama proses hidup, sehingga
     * dependensi ber-lingkup akses WAJIB di-inject per metode.
     */

    public function index(
        Request $request,
        FamilyRepository $families,
        RegionService $regions,
    ): Response {
        $page = $families->search(
            $request->string('q')->toString() ?: null,
            array_filter($request->only(['region_id', 'welfare_status_code']))
        );

        return Inertia::render('Kependudukan/Keluarga/Index', [
            'families' => [
                'data' => array_map(ResidentPresenter::family(...), $page->items()),
                'prev_url' => $page->previousPageUrl(),
                'next_url' => $page->nextPageUrl(),
                'count' => count($page->items()),
            ],
            'q' => $request->string('q')->toString(),
            'filters' => $request->only(['region_id', 'welfare_status_code']),
            'regions' => $regions->rtOptions(),
            'references' => ReferenceData::forFamilyForm(),
        ]);
    }

    public function show(
        string $id,
        FamilyRepository $families,
        ResidentRepository $residents,
        ChangeHistoryService $history,
    ): Response {
        $family = $families->findOrFail($id);
        $family->load(['region.parent.parent']);

        return Inertia::render('Kependudukan/Keluarga/Show', [
            'family' => ResidentPresenter::family($family),
            'members' => $residents->membersOfFamily($family->id)
                ->map(ResidentPresenter::member(...))->values()->all(),
            'history' => HistoryPresenter::collection($history->forEntity($family)),
        ]);
    }

    public function create(RegionService $regions): Response
    {
        $this->authorize('keluarga.tambah');

        return Inertia::render('Kependudukan/Keluarga/Form', [
            'family' => null,
            'regions' => $regions->rtOptions(),
            'references' => ReferenceData::forFamilyForm(),
        ]);
    }

    public function store(StoreFamilyRequest $request, FamilyService $service): RedirectResponse
    {
        $this->authorize('keluarga.tambah');

        $family = $service->create($request->validated());

        return redirect()
            ->route('keluarga.show', $family->id)
            ->with('status', 'Kartu keluarga berhasil dibuat. Tambahkan anggotanya sekarang.');
    }

    public function edit(string $id, FamilyRepository $families, RegionService $regions): Response
    {
        $this->authorize('keluarga.ubah');

        $family = $families->findOrFail($id);
        $family->load('region.parent.parent');

        return Inertia::render('Kependudukan/Keluarga/Form', [
            'family' => ResidentPresenter::family($family),
            'regions' => $regions->rtOptions(),
            'references' => ReferenceData::forFamilyForm(),
        ]);
    }

    public function update(
        UpdateFamilyRequest $request,
        string $id,
        FamilyRepository $families,
        FamilyService $service,
    ): RedirectResponse {
        $this->authorize('keluarga.ubah');

        $family = $families->findOrFail($id);
        $service->update($family, $request->validated());

        return redirect()
            ->route('keluarga.show', $family->id)
            ->with('status', 'Perubahan kartu keluarga berhasil disimpan.');
    }

    public function destroy(
        Request $request,
        string $id,
        FamilyRepository $families,
        FamilyService $service,
    ): RedirectResponse {
        $this->authorize('keluarga.hapus');

        $family = $families->findOrFail($id);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        $service->delete($family, (string) $validated['reason']);

        return redirect()->route('keluarga.index')
            ->with('status', 'Kartu keluarga dihapus.');
    }

    /** Pindahkan anggota ke KK lain — FR-PDK-09. */
    public function moveMember(
        Request $request,
        string $id,
        FamilyRepository $families,
        ResidentRepository $residents,
        FamilyService $service,
    ): RedirectResponse {
        $this->authorize('keluarga.ubah');

        $validated = $request->validate([
            'resident_id' => ['required', 'uuid'],
            'target_family_id' => ['required', 'uuid', 'different:current_family_id'],
            'family_relation_code' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'reason.required' => 'Alasan pemindahan wajib diisi agar riwayat dapat ditelusuri.',
            'target_family_id.different' => 'Kartu keluarga tujuan harus berbeda dari asal.',
        ]);

        $resident = $residents->findOrFail((string) $validated['resident_id']);
        $target = $families->findOrFail((string) $validated['target_family_id']);

        $service->moveMember(
            $resident,
            $target,
            (string) $validated['family_relation_code'],
            (string) $validated['reason'],
        );

        return redirect()->route('keluarga.show', $id)
            ->with('status', "{$resident->full_name} dipindahkan ke kartu keluarga lain.");
    }

    public function revealKkNumber(
        string $id,
        FamilyRepository $families,
        FamilyService $service,
    ): JsonResponse {
        $this->authorize('data_pribadi.lihat_penuh');

        $family = $families->findOrFail($id);

        return response()->json([
            'nomor_kk' => $service->revealKkNumber($family),
            'catatan' => 'Pembukaan data ini tercatat di log aktivitas.',
        ]);
    }
}
