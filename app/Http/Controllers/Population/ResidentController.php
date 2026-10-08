<?php

declare(strict_types=1);

namespace App\Http\Controllers\Population;

use App\Http\Controllers\Controller;
use App\Http\Requests\Population\StoreResidentRequest;
use App\Http\Requests\Population\UpdateResidentRequest;
use App\Http\Resources\HistoryPresenter;
use App\Http\Resources\ResidentPresenter;
use App\Repositories\FamilyRepository;
use App\Repositories\ResidentRepository;
use App\Services\Population\ChangeHistoryService;
use App\Services\Population\RegionService;
use App\Services\Population\ResidentService;
use App\Support\ReferenceData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Modul kependudukan — FR-PDK-01, 04, 05, 06, 07.
 *
 * Kontroler ini TIDAK pernah membangun kueri ke tabel `residents` sendiri;
 * semuanya lewat ResidentRepository (lingkup akses) dan ResidentService
 * (aturan bisnis). Aturan ini ditegakkan tests/Architecture.
 */
final class ResidentController extends Controller
{
    /*
     * TIDAK ADA KONSTRUKTOR — ini disengaja, dan penting.
     *
     * Illuminate\Routing\Route::getController() MENYIMPAN instance controller
     * pada objek Route, dan objek Route hidup selama proses berjalan. Akibatnya
     * dependensi yang di-inject lewat konstruktor hanya dibuat SEKALI per proses.
     *
     * Untuk repository yang membawa AccessScope, itu berarti lingkup akses
     * pengguna PERTAMA ikut terpakai oleh pengguna berikutnya — kebocoran data
     * yang tidak muncul di PHP-FPM (satu proses satu permintaan) tetapi pasti
     * terjadi di Laravel Octane.
     *
     * Dependensi ber-lingkup karena itu di-inject per METODE: Laravel
     * menyelesaikan parameter metode pada setiap dispatch, bukan sekali saja.
     *
     * Dijaga oleh tests/Architecture/RepositoryBoundaryTest.php.
     */

    public function index(
        Request $request,
        ResidentRepository $residents,
        RegionService $regions,
    ): Response {
        $filters = $request->only([
            'region_id', 'gender', 'status', 'religion_code', 'age_min', 'age_max',
        ]);

        $page = $residents->search(
            $request->string('q')->toString() ?: null,
            array_filter($filters, fn ($v) => $v !== null && $v !== '')
        );

        return Inertia::render('Kependudukan/Penduduk/Index', [
            'residents' => [
                'data' => array_map(
                    ResidentPresenter::row(...), $page->items()
                ),
                'prev_url' => $page->previousPageUrl(),
                'next_url' => $page->nextPageUrl(),
                'count' => count($page->items()),
            ],
            'q' => $request->string('q')->toString(),
            'filters' => $filters,
            'regions' => $regions->rtOptions(),
            'statusCounts' => $residents->countByStatus(),
        ]);
    }

    public function show(
        string $id,
        ResidentRepository $residents,
        ChangeHistoryService $history,
    ): Response {
        $resident = $residents->findOrFail($id);
        $resident->load(['family', 'region.parent.parent']);

        $members = $resident->family_id === null
            ? collect()
            : $residents->membersOfFamily($resident->family_id);

        $family = $resident->family;

        return Inertia::render('Kependudukan/Penduduk/Show', [
            'resident' => ResidentPresenter::detail($resident),
            'members' => $members->map(ResidentPresenter::member(...))->values()->all(),
            'history' => HistoryPresenter::collection($history->forEntity($resident)),
            'family' => $family === null ? null : [
                'id' => $family->id,
                'kk_number_masked' => $family->kk_number_masked,
            ],
        ]);
    }

    public function create(
        Request $request,
        FamilyRepository $families,
        RegionService $regions,
    ): Response {
        $this->authorize('penduduk.tambah');

        return Inertia::render('Kependudukan/Penduduk/Form', [
            'resident' => null,
            'families' => $this->familyOptions($families),
            'selectedFamilyId' => $request->string('family_id')->toString() ?: null,
            'regions' => $regions->rtOptions(),
            'references' => ReferenceData::forResidentForm(),
        ]);
    }

    public function store(StoreResidentRequest $request, ResidentService $service): RedirectResponse
    {
        $this->authorize('penduduk.tambah');

        $resident = $service->create($request->residentData());

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

    public function edit(
        string $id,
        ResidentRepository $residents,
        FamilyRepository $families,
        RegionService $regions,
    ): Response {
        $this->authorize('penduduk.ubah');

        $resident = $residents->findOrFail($id);
        $resident->load('region.parent.parent');

        return Inertia::render('Kependudukan/Penduduk/Form', [
            'resident' => ResidentPresenter::detail($resident),
            'families' => $this->familyOptions($families),
            'selectedFamilyId' => $resident->family_id,
            'regions' => $regions->rtOptions(),
            'references' => ReferenceData::forResidentForm(),
        ]);
    }

    /**
     * Pilihan kartu keluarga untuk formulir.
     *
     * Dibatasi 50 teratas: daftar <select> dengan ribuan entri tidak dapat
     * dipakai. Bila kelak jumlahnya besar, ganti dengan pencarian bertahap —
     * bukan dengan menaikkan batasnya.
     *
     * @return list<array{value: string, label: string}>
     */
    private function familyOptions(FamilyRepository $families): array
    {
        return array_map(
            fn ($family): array => [
                'value' => $family->id,
                'label' => $family->kk_number_masked.' — '.$family->address,
            ],
            $families->search(null, [], 50)->items(),
        );
    }

    public function update(
        UpdateResidentRequest $request,
        string $id,
        ResidentRepository $residents,
        ResidentService $service,
    ): RedirectResponse {
        $this->authorize('penduduk.ubah');

        $resident = $residents->findOrFail($id);
        $service->update($resident, $request->residentData());

        return redirect()
            ->route('penduduk.show', $resident->id)
            ->with('status', 'Perubahan data berhasil disimpan.');
    }

    public function destroy(
        Request $request,
        string $id,
        ResidentRepository $residents,
        ResidentService $service,
    ): RedirectResponse {
        $this->authorize('penduduk.hapus');

        $resident = $residents->findOrFail($id);
        $validated = $request->validate(
            ['reason' => ['required', 'string', 'min:5', 'max:500']],
            ['reason.required' => 'Alasan penghapusan wajib diisi.',
                'reason.min' => 'Jelaskan alasannya sedikit lebih rinci.']
        );

        $name = $resident->full_name;
        $service->delete($resident, (string) $validated['reason']);

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
    public function revealNik(
        string $id,
        ResidentRepository $residents,
        ResidentService $service,
    ): JsonResponse {
        $this->authorize('data_pribadi.lihat_penuh');

        $resident = $residents->findOrFail($id);

        return response()->json([
            'nik' => $service->revealNik($resident),
            'catatan' => 'Pembukaan data ini tercatat di log aktivitas.',
        ]);
    }

    /** Autocomplete pencarian penduduk (FR-PDK-04). */
    public function suggest(Request $request, ResidentRepository $residents): JsonResponse
    {
        return response()->json([
            'data' => $residents->suggest($request->string('q')->toString()),
        ]);
    }
}
