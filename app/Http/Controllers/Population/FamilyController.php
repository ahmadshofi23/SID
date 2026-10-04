<?php

declare(strict_types=1);

namespace App\Http\Controllers\Population;

use App\Http\Controllers\Controller;
use App\Http\Requests\Population\StoreFamilyRequest;
use App\Http\Requests\Population\UpdateFamilyRequest;
use App\Repositories\FamilyRepository;
use App\Repositories\ResidentRepository;
use App\Services\Population\ChangeHistoryService;
use App\Services\Population\FamilyService;
use App\Services\Population\RegionService;
use App\Support\ReferenceData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class FamilyController extends Controller
{
    public function __construct(
        private readonly FamilyRepository $families,
        private readonly ResidentRepository $residents,
        private readonly FamilyService $service,
        private readonly RegionService $regions,
    ) {}

    public function index(Request $request): View
    {
        return view('kependudukan.keluarga.index', [
            'families' => $this->families->search(
                $request->string('q')->toString() ?: null,
                array_filter($request->only(['region_id', 'welfare_status_code']))
            ),
            'q' => $request->string('q')->toString(),
            'filters' => $request->only(['region_id', 'welfare_status_code']),
            'regions' => $this->regions->rtOptions(),
            'references' => ReferenceData::forFamilyForm(),
        ]);
    }

    public function show(string $id, ChangeHistoryService $history): View
    {
        $family = $this->families->findOrFail($id);
        $family->load(['region.parent.parent']);

        return view('kependudukan.keluarga.show', [
            'family' => $family,
            'members' => $this->residents->membersOfFamily($family->id),
            'history' => $history->forEntity($family),
            'references' => ReferenceData::labels(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('keluarga.tambah');

        return view('kependudukan.keluarga.form', [
            'family' => null,
            'regions' => $this->regions->rtOptions(),
            'references' => ReferenceData::forFamilyForm(),
        ]);
    }

    public function store(StoreFamilyRequest $request): RedirectResponse
    {
        $this->authorize('keluarga.tambah');

        $family = $this->service->create($request->validated());

        return redirect()
            ->route('keluarga.show', $family->id)
            ->with('status', 'Kartu keluarga berhasil dibuat. Tambahkan anggotanya sekarang.');
    }

    public function edit(string $id): View
    {
        $this->authorize('keluarga.ubah');

        return view('kependudukan.keluarga.form', [
            'family' => $this->families->findOrFail($id),
            'regions' => $this->regions->rtOptions(),
            'references' => ReferenceData::forFamilyForm(),
        ]);
    }

    public function update(UpdateFamilyRequest $request, string $id): RedirectResponse
    {
        $this->authorize('keluarga.ubah');

        $family = $this->families->findOrFail($id);
        $this->service->update($family, $request->validated());

        return redirect()
            ->route('keluarga.show', $family->id)
            ->with('status', 'Perubahan kartu keluarga berhasil disimpan.');
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        $this->authorize('keluarga.hapus');

        $family = $this->families->findOrFail($id);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        $this->service->delete($family, (string) $validated['reason']);

        return redirect()->route('keluarga.index')
            ->with('status', 'Kartu keluarga dihapus.');
    }

    /** Pindahkan anggota ke KK lain — FR-PDK-09. */
    public function moveMember(Request $request, string $id): RedirectResponse
    {
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

        $resident = $this->residents->findOrFail((string) $validated['resident_id']);
        $target = $this->families->findOrFail((string) $validated['target_family_id']);

        $this->service->moveMember(
            $resident,
            $target,
            (string) $validated['family_relation_code'],
            (string) $validated['reason'],
        );

        return redirect()->route('keluarga.show', $id)
            ->with('status', "{$resident->full_name} dipindahkan ke kartu keluarga lain.");
    }

    public function revealKkNumber(string $id): JsonResponse
    {
        $this->authorize('data_pribadi.lihat_penuh');

        $family = $this->families->findOrFail($id);

        return response()->json([
            'nomor_kk' => $this->service->revealKkNumber($family),
            'catatan' => 'Pembukaan data ini tercatat di log aktivitas.',
        ]);
    }
}
