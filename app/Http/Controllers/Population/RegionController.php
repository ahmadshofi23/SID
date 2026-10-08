<?php

declare(strict_types=1);

namespace App\Http\Controllers\Population;

use App\Http\Controllers\Controller;
use App\Http\Requests\Population\StoreRegionRequest;
use App\Http\Requests\Population\UpdateRegionRequest;
use App\Models\Region;
use App\Services\Population\RegionService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class RegionController extends Controller
{
    /*
     * Tanpa konstruktor — lihat penjelasan di ResidentController.
     *
     * RegionService sendiri bergantung pada ResidentRepository (untuk memeriksa
     * apakah wilayah masih dihuni), sehingga ikut membawa AccessScope.
     */

    public function index(RegionService $service): Response
    {
        return Inertia::render('Kependudukan/Wilayah/Index', [
            'tree' => $service->tree()->map($this->presentRegion(...))->values()->all(),
        ]);
    }

    /**
     * Bentuk wilayah untuk ditampilkan, beserta turunannya.
     *
     * @return array<string, mixed>
     */
    private function presentRegion(Region $region): array
    {
        return [
            'id' => $region->id,
            'name' => $region->name,
            'level' => $region->level,
            'code' => $region->code,
            'head_name' => $region->head_name,
            'children' => $region->children->map($this->presentRegion(...))->values()->all(),
        ];
    }

    public function create(): Response
    {
        $this->authorize('wilayah.kelola');

        return Inertia::render('Kependudukan/Wilayah/Form', [
            'region' => null,
            'parents' => $this->parentOptions(),
        ]);
    }

    public function store(StoreRegionRequest $request, RegionService $service): RedirectResponse
    {
        $this->authorize('wilayah.kelola');

        $region = $service->create($request->validated());

        return redirect()->route('wilayah.index')
            ->with('status', "Wilayah {$region->name} berhasil ditambahkan.");
    }

    public function edit(Region $region): Response
    {
        $this->authorize('wilayah.kelola');

        return Inertia::render('Kependudukan/Wilayah/Form', [
            'region' => [
                'id' => $region->id,
                'name' => $region->name,
                'level' => $region->level,
                'code' => $region->code,
                'parent_id' => $region->parent_id,
                'head_name' => $region->head_name,
                'head_phone' => $region->head_phone,
            ],
            'parents' => $this->parentOptions(),
        ]);
    }

    public function update(
        UpdateRegionRequest $request,
        Region $region,
        RegionService $service,
    ): RedirectResponse {
        $this->authorize('wilayah.kelola');

        $service->update($region, $request->validated());

        return redirect()->route('wilayah.index')
            ->with('status', 'Perubahan wilayah berhasil disimpan.');
    }

    public function destroy(Region $region, RegionService $service): RedirectResponse
    {
        $this->authorize('wilayah.kelola');

        $name = $region->name;
        $service->delete($region);

        return redirect()->route('wilayah.index')
            ->with('status', "Wilayah {$name} dihapus.");
    }

    /**
     * Pilihan induk untuk formulir, dikelompokkan per tingkat.
     *
     * @return array<string, array<string, string>>
     */
    private function parentOptions(): array
    {
        $options = ['dusun' => [], 'rw' => []];

        foreach (Region::query()->with('parent')->whereIn('level', ['dusun', 'rw'])
            ->orderBy('path')->get() as $region) {
            $options[$region->level][$region->id] = $region->displayPath();
        }

        return $options;
    }
}
