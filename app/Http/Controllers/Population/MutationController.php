<?php

declare(strict_types=1);

namespace App\Http\Controllers\Population;

use App\Http\Controllers\Controller;
use App\Models\ResidentMutation;
use App\Repositories\ResidentRepository;
use App\Services\Population\MutationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mutasi penduduk — FR-PDK-10.
 *
 * Tanpa konstruktor (KEP-009).
 */
final class MutationController extends Controller
{
    public function index(
        Request $request,
        MutationService $mutations,
    ): Response {
        $filters = $request->only(['type', 'from', 'to']);
        $page = $mutations->paginate(array_filter($filters));

        return Inertia::render('Kependudukan/Mutasi/Index', [
            'mutations' => [
                'data' => array_map(
                    fn (ResidentMutation $m): array => [
                        'id' => $m->id,
                        'type' => $m->type,
                        'type_label' => ResidentMutation::typeLabels()[$m->type] ?? $m->type,
                        'date' => $m->mutation_date->locale('id')->translatedFormat('d F Y'),
                        'resident_id' => $m->resident_id,
                        'resident_name' => $m->resident->full_name,
                        'resident_nik' => $m->resident->nik_masked,
                        'reason' => $m->reason,
                    ],
                    $page->items(),
                ),
                'prev_url' => $page->previousPageUrl(),
                'next_url' => $page->nextPageUrl(),
                'count' => count($page->items()),
            ],
            'filters' => $filters,
            'types' => ResidentMutation::typeLabels(),
        ]);
    }

    public function create(string $residentId, ResidentRepository $residents): Response
    {
        $this->authorize('mutasi.tambah');

        $resident = $residents->findOrFail($residentId);

        return Inertia::render('Kependudukan/Mutasi/Form', [
            'resident' => [
                'id' => $resident->id,
                'full_name' => $resident->full_name,
                'nik_masked' => $resident->nik_masked,
                'status' => $resident->status,
                'birth_date' => $resident->birth_date->format('Y-m-d'),
                'is_head' => $resident->isHeadOfFamily(),
            ],
        ]);
    }

    public function store(
        Request $request,
        string $residentId,
        ResidentRepository $residents,
        MutationService $mutations,
    ): RedirectResponse {
        $this->authorize('mutasi.tambah');

        $resident = $residents->findOrFail($residentId);

        $validated = $request->validate([
            'type' => ['required', Rule::in([
                ResidentMutation::TYPE_DEATH,
                ResidentMutation::TYPE_MOVE_OUT,
                ResidentMutation::TYPE_MOVE_IN,
                ResidentMutation::TYPE_BIRTH,
            ])],
            'mutation_date' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['nullable', 'string', 'max:500'],
            'document_no' => ['nullable', 'string', 'max:100'],
            'reported_by' => ['nullable', 'string', 'max:150'],

            'death_cause' => ['nullable', 'string', 'max:100'],
            'death_place' => ['nullable', 'string', 'max:150'],
            'destination_address' => ['nullable', 'string', 'max:255'],
            'origin_address' => ['nullable', 'string', 'max:255'],
            'birth_weight' => ['nullable', 'numeric', 'min:0', 'max:15'],
            'birth_length' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'birth_helper' => ['nullable', 'string', 'max:50'],
        ], [
            'mutation_date.before_or_equal' => 'Tanggal mutasi tidak boleh melebihi hari ini.',
        ]);

        $label = match ($validated['type']) {
            ResidentMutation::TYPE_DEATH => 'kematian',
            ResidentMutation::TYPE_MOVE_OUT => 'kepindahan keluar',
            ResidentMutation::TYPE_MOVE_IN => 'kepindahan datang',
            default => 'kelahiran',
        };

        match ($validated['type']) {
            ResidentMutation::TYPE_DEATH => $mutations->recordDeath($resident, $validated),
            ResidentMutation::TYPE_MOVE_OUT => $mutations->recordMoveOut($resident, $validated),
            ResidentMutation::TYPE_MOVE_IN => $mutations->recordMoveIn($resident, $validated),
            default => $mutations->recordBirth($resident, $validated),
        };

        return redirect()
            ->route('penduduk.show', $resident->id)
            ->with('status', "Mutasi {$label} {$resident->full_name} berhasil dicatat.");
    }
}
