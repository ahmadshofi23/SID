<?php

declare(strict_types=1);

namespace App\Http\Controllers\Complaint;

use App\Auth\AccessScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\ComplaintPresenter;
use App\Models\Complaint;
use App\Models\User;
use App\Repositories\ComplaintRepository;
use App\Services\Complaint\ComplaintService;
use App\Support\ReferenceData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Antrean & tindak lanjut pengaduan oleh perangkat desa — UC-14, FR-PGD-03
 * s.d. FR-PGD-07.
 *
 * TIDAK ADA satu pun pemeriksaan "apakah pengaduan ini boleh saya lihat" yang
 * ditulis tangan di kelas ini: pengaduan di luar lingkup pengguna tidak
 * terlihat oleh ComplaintRepository, sehingga menjadi 404 dengan sendirinya
 * (docs/06 §4). Yang ditulis di sini hanyalah pemeriksaan IZIN, karena izin
 * dan lingkup adalah dua pertanyaan berbeda — "boleh melakukan apa" dan
 * "atas data yang mana".
 *
 * Tanpa konstruktor (KEP-009).
 */
final class ComplaintController extends Controller
{
    public function index(Request $request, ComplaintRepository $complaints): Response
    {
        $this->authorize('pengaduan.lihat');

        $filters = [
            /*
             * Bawaannya ANTREAN TERBUKA, bukan seluruh pengaduan: layar yang
             * dibuka petugas setiap pagi adalah pekerjaan yang menunggu, dan
             * daftar yang memuat seluruh pengaduan sejak dulu hanya membuatnya
             * menggulir.
             *
             * Dibedakan antara parameter TIDAK ADA (bawaan: terbuka) dan
             * parameter ADA tetapi kosong (sengaja: semua status). Tanpa
             * pembedaan itu — yaitu dengan `?: 'terbuka'` — pilihan
             * "— Semua —" di layar tetap menghasilkan daftar terbuka saja,
             * dan petugas yang mencari pengaduan lama akan menyimpulkan
             * pengaduan itu hilang dari sistem.
             */
            'status' => $request->has('status')
                ? $request->string('status')->toString()
                : 'terbuka',
            'category_code' => $request->string('category_code')->toString(),
            'priority' => $request->string('priority')->toString(),
            'assigned_to' => $request->string('assigned_to')->toString(),
            'q' => $request->string('q')->toString(),
            'overdue' => $request->boolean('overdue'),
        ];

        $page = $complaints->queue($filters);

        return Inertia::render('Pengaduan/Index', [
            'pengaduan' => [
                'data' => array_map(
                    fn (Complaint $c): array => ComplaintPresenter::row($c),
                    $page->items(),
                ),
                'prev_url' => $page->previousPageUrl(),
                'next_url' => $page->nextPageUrl(),
                'count' => count($page->items()),
            ],
            'filters' => $filters,
            'statuses' => Complaint::statusLabels(),
            'priorities' => Complaint::priorityLabels(),
            'kategori' => ReferenceData::get('complaint_categories'),
            'summary' => [
                'by_status' => $complaints->countByStatus(),
                'overdue' => $complaints->countOverdue(),
            ],
            'petugas' => $this->assignableOfficers(),
        ]);
    }

    public function show(string $id, ComplaintRepository $complaints, AccessScope $scope): Response
    {
        $this->authorize('pengaduan.lihat');

        // findOrFail() repository: di luar lingkup -> 404, bukan 403.
        $complaint = $complaints->findOrFail($id);
        $complaint->load(['actions', 'region:id,name,path', 'assignee:id,name']);

        return Inertia::render('Pengaduan/Detail', [
            'pengaduan' => ComplaintPresenter::forOfficer($complaint),
            'statuses' => Complaint::statusLabels(),
            'petugas' => $this->assignableOfficers(),
            /*
             * Kewenangan dikirim ke peramban sebagai DATA, bukan disimpulkan
             * ulang di Vue dari daftar izin (KEP-012). Dua alasan: layar harus
             * dapat mengatakan MENGAPA sebuah tombol tidak ada, dan aturan
             * yang ditulis dua kali akan berbeda pelan-pelan — biasanya pada
             * sisi yang lebih longgar.
             */
            'izin' => [
                'tindaklanjut' => $scope->can('pengaduan.tindaklanjut'),
                'tugaskan' => $scope->can('pengaduan.tugaskan'),
            ],
        ]);
    }

    /**
     * Satu pintu untuk seluruh perubahan status — FR-PGD-03.
     *
     * Digabung menjadi satu rute, bukan satu rute per transisi, karena
     * mesin statusnya memang satu: ComplaintService yang menentukan transisi
     * mana yang sah, dan controller tidak boleh punya pendapat kedua tentang
     * itu. Rute terpisah akan menggoda orang menambahkan "jalan pintas" yang
     * melewati guardTransitionAllowed().
     */
    public function transition(
        string $id,
        Request $request,
        ComplaintRepository $complaints,
        ComplaintService $service,
        AccessScope $scope,
    ): RedirectResponse {
        $this->authorize('pengaduan.tindaklanjut');

        $complaint = $complaints->findOrFail($id);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', array_keys(Complaint::statusLabels()))],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        $note = (string) ($validated['note'] ?? '');

        match ($validated['status']) {
            Complaint::STATUS_ACCEPTED => $service->accept($scope, $complaint, $note ?: null),
            Complaint::STATUS_IN_PROGRESS => $service->startWork($scope, $complaint, $note ?: null),
            Complaint::STATUS_DONE => $service->resolve($scope, $complaint, $note),
            Complaint::STATUS_REJECTED => $service->reject($scope, $complaint, $note),
            default => abort(422, 'Status tujuan tidak dikenali.'),
        };

        return back()->with('status', 'Status pengaduan diperbarui.');
    }

    public function assign(
        string $id,
        Request $request,
        ComplaintRepository $complaints,
        ComplaintService $service,
        AccessScope $scope,
    ): RedirectResponse {
        $this->authorize('pengaduan.tugaskan');

        $complaint = $complaints->findOrFail($id);

        $validated = $request->validate([
            'assigned_to' => ['nullable', 'uuid', 'exists:users,id'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $assignee = ($validated['assigned_to'] ?? null) === null
            ? null
            : User::query()->findOrFail($validated['assigned_to']);

        $service->assign($scope, $complaint, $assignee, $validated['note'] ?? null);

        return back()->with('status', $assignee === null
            ? 'Penugasan dicabut.'
            : 'Pengaduan ditugaskan kepada '.$assignee->name.'.');
    }

    /** Catatan tindak lanjut tanpa mengubah status — FR-PGD-05. */
    public function followUp(
        string $id,
        Request $request,
        ComplaintRepository $complaints,
        ComplaintService $service,
        AccessScope $scope,
    ): RedirectResponse {
        $this->authorize('pengaduan.tindaklanjut');

        $complaint = $complaints->findOrFail($id);

        $validated = $request->validate([
            'note' => ['required', 'string', 'max:5000'],
            'is_visible_to_reporter' => ['sometimes', 'boolean'],
        ], [
            'note.required' => 'Tuliskan tindak lanjutnya.',
        ]);

        $service->addFollowUp(
            $scope,
            $complaint,
            $validated['note'],
            // Bawaannya TERLIHAT pelapor: catatan yang disembunyikan harus
            // selalu merupakan pilihan sadar, bukan keadaan bawaan.
            (bool) ($validated['is_visible_to_reporter'] ?? true),
        );

        return back()->with('status', 'Tindak lanjut dicatat.');
    }

    /** Publikasikan ringkasan ke portal — FR-PGD-07, opt-in per kasus. */
    public function publish(
        string $id,
        Request $request,
        ComplaintRepository $complaints,
        ComplaintService $service,
        AccessScope $scope,
    ): RedirectResponse {
        $this->authorize('pengaduan.tindaklanjut');

        $complaint = $complaints->findOrFail($id);

        $terbit = $request->boolean('is_published');

        $service->setPublished($scope, $complaint, $terbit);

        return back()->with('status', $terbit
            ? 'Ringkasan pengaduan terbit di portal desa.'
            : 'Ringkasan pengaduan ditarik dari portal desa.');
    }

    /**
     * Petugas yang dapat MENERIMA tugas.
     *
     * Bukan "semua pengguna aktif": menugaskan kepada orang yang tidak dapat
     * menindaklanjuti berarti pengaduan resmi "ada yang menangani" sementara
     * tidak seorang pun dapat menyentuhnya — kegagalan yang paling sulit
     * terlihat, karena di layar semuanya tampak beres. Daftar ini adalah
     * cerminan dari pemeriksaan di ComplaintService::assign(); keduanya
     * membaca izin yang sama, sehingga layar tidak pernah menawarkan pilihan
     * yang kemudian ditolak.
     *
     * @return list<array{value: string, label: string}>
     */
    private function assignableOfficers(): array
    {
        return User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->whereHas('roles.permissions', fn (Builder $q) => $q
                ->where('code', 'pengaduan.tindaklanjut'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u): array => ['value' => $u->id, 'label' => $u->name])
            ->values()
            ->all();
    }
}
