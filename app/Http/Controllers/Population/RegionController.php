<?php

declare(strict_types=1);

namespace App\Http\Controllers\Population;

use App\Http\Controllers\Controller;
use App\Http\Requests\Population\StoreRegionRequest;
use App\Http\Requests\Population\UpdateRegionRequest;
use App\Models\Region;
use App\Services\Population\RegionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class RegionController extends Controller
{
    public function __construct(private readonly RegionService $service) {}

    public function index(): View
    {
        return view('kependudukan.wilayah.index', [
            'tree' => $this->service->tree(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('wilayah.kelola');

        return view('kependudukan.wilayah.form', [
            'region' => null,
            'parents' => $this->parentOptions(),
        ]);
    }

    public function store(StoreRegionRequest $request): RedirectResponse
    {
        $this->authorize('wilayah.kelola');

        $region = $this->service->create($request->validated());

        return redirect()->route('wilayah.index')
            ->with('status', "Wilayah {$region->name} berhasil ditambahkan.");
    }

    public function edit(Region $region): View
    {
        $this->authorize('wilayah.kelola');

        return view('kependudukan.wilayah.form', [
            'region' => $region,
            'parents' => $this->parentOptions(),
        ]);
    }

    public function update(UpdateRegionRequest $request, Region $region): RedirectResponse
    {
        $this->authorize('wilayah.kelola');

        $this->service->update($region, $request->validated());

        return redirect()->route('wilayah.index')
            ->with('status', 'Perubahan wilayah berhasil disimpan.');
    }

    public function destroy(Region $region): RedirectResponse
    {
        $this->authorize('wilayah.kelola');

        $name = $region->name;
        $this->service->delete($region);

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
