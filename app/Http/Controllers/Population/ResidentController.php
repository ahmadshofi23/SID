<?php

declare(strict_types=1);

namespace App\Http\Controllers\Population;

use App\Http\Controllers\Controller;
use App\Http\Requests\Population\StoreResidentRequest;
use App\Http\Requests\Population\UpdateResidentRequest;
use App\Repositories\FamilyRepository;
use App\Repositories\ResidentRepository;
use App\Services\Population\ChangeHistoryService;
use App\Services\Population\RegionService;
use App\Services\Population\ResidentService;
use App\Support\ReferenceData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Modul kependudukan — FR-PDK-01, 04, 05, 06, 07.
 *
 * Kontroler ini TIDAK pernah membangun kueri ke tabel `residents` sendiri;
 * semuanya lewat ResidentRepository (lingkup akses) dan ResidentService
 * (aturan bisnis). Aturan ini ditegakkan tests/Architecture.
 */
final class ResidentController extends Controller
{
    public function __construct(
        private readonly ResidentRepository $residents,
        private readonly ResidentService $service,
        private readonly RegionService $regions,
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->only([
            'region_id', 'gender', 'status', 'religion_code', 'age_min', 'age_max',
        ]);

        return view('kependudukan.penduduk.index', [
            'residents' => $this->residents->search(
                $request->string('q')->toString() ?: null,
                array_filter($filters, fn ($v) => $v !== null && $v !== '')
            ),
            'q' => $request->string('q')->toString(),
            'filters' => $filters,
            'regions' => $this->regions->rtOptions(),
            'references' => ReferenceData::forResidentFilters(),
            'statusCounts' => $this->residents->countByStatus(),
        ]);
    }

    public function show(string $id, ChangeHistoryService $history): View
    {
        $resident = $this->residents->findOrFail($id);
        $resident->load(['family', 'region.parent.parent']);

        return view('kependudukan.penduduk.show', [
            'resident' => $resident,
            'members' => $resident->family_id === null
                ? collect()
                : $this->residents->membersOfFamily($resident->family_id),
            'history' => $history->forEntity($resident),
            'references' => ReferenceData::labels(),
        ]);
    }

    public function create(FamilyRepository $families, Request $request): View
    {
        $this->authorize('penduduk.tambah');

        return view('kependudukan.penduduk.form', [
            'resident' => null,
            'families' => $families->search(null, [], 50)->items(),
            'selectedFamilyId' => $request->string('family_id')->toString() ?: null,
            'regions' => $this->regions->rtOptions(),
            'references' => ReferenceData::forResidentForm(),
        ]);
    }

    public function store(StoreResidentRequest $request): RedirectResponse
    {
        $this->authorize('penduduk.tambah');

        $resident = $this->service->create($request->residentData());

        $redirect = redirect()
            ->route('penduduk.show', $resident->id)
            ->with('status', "Data {$resident->full_name} berhasil disimpan.");

        // Peringatan NIK tidak memblokir penyimpanan, tetapi tetap disampaikan
        // agar petugas memeriksa ulang (FR-PDK-02).
        if (($warnings = $request->nikWarnings()) !== []) {
            $redirect->with('warning', 'Perlu diperiksa: '.implode(' ', $warnings));
        }

        return $redirect;
    }

    public function edit(string $id, FamilyRepository $families): View
    {
        $this->authorize('penduduk.ubah');

        $resident = $this->residents->findOrFail($id);

        return view('kependudukan.penduduk.form', [
            'resident' => $resident,
            'families' => $families->search(null, [], 50)->items(),
            'selectedFamilyId' => $resident->family_id,
            'regions' => $this->regions->rtOptions(),
            'references' => ReferenceData::forResidentForm(),
        ]);
    }

    public function update(UpdateResidentRequest $request, string $id): RedirectResponse
    {
        $this->authorize('penduduk.ubah');

        $resident = $this->residents->findOrFail($id);
        $this->service->update($resident, $request->residentData());

        return redirect()
            ->route('penduduk.show', $resident->id)
            ->with('status', 'Perubahan data berhasil disimpan.');
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        $this->authorize('penduduk.hapus');

        $resident = $this->residents->findOrFail($id);
        $validated = $request->validate(
            ['reason' => ['required', 'string', 'min:5', 'max:500']],
            ['reason.required' => 'Alasan penghapusan wajib diisi.',
                'reason.min' => 'Jelaskan alasannya sedikit lebih rinci.']
        );

        $name = $resident->full_name;
        $this->service->delete($resident, (string) $validated['reason']);

        return redirect()
            ->route('penduduk.index')
            ->with('status', "Data {$name} dihapus. Data masih dapat dipulihkan oleh Admin Desa.");
    }

    /**
     * Ungkap NIK penuh — RULE-07.
     *
     * Memerlukan izin terpisah `data_pribadi.lihat_penuh` (hanya Admin Desa),
     * dan SETIAP pemanggilan tercatat di audit log.
     */
    public function revealNik(string $id): JsonResponse
    {
        $this->authorize('data_pribadi.lihat_penuh');

        $resident = $this->residents->findOrFail($id);

        return response()->json([
            'nik' => $this->service->revealNik($resident),
            'catatan' => 'Pembukaan data ini tercatat di log aktivitas.',
        ]);
    }

    /** Autocomplete pencarian penduduk (FR-PDK-04). */
    public function suggest(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->residents->suggest($request->string('q')->toString()),
        ]);
    }
}
