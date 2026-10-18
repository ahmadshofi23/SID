<?php

declare(strict_types=1);

namespace App\Http\Controllers\Citizen;

use App\Http\Controllers\Controller;
use App\Models\Resident;
use App\Models\Role;
use App\Models\User;
use App\Repositories\ResidentRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Citizen\CitizenRegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Antrean verifikasi akun warga oleh petugas — FR-AUT-09, UC-15.
 *
 * Dijaga izin `pengguna.verifikasi`, yang pada matriks RBAC hanya dimiliki
 * Admin Desa. Keputusan ini memberi satu orang akun warga yang sah di desa —
 * sama beratnya dengan mengubah data kependudukan, bukan pekerjaan loket.
 *
 * Tanpa konstruktor (KEP-009).
 */
final class CitizenVerificationController extends Controller
{
    public function index(ResidentRepository $residents): Response
    {
        $this->authorize('pengguna.verifikasi');

        $pending = User::query()
            ->where('status', User::STATUS_PENDING)
            ->whereHas('roles', fn ($q) => $q->where('code', Role::WARGA))
            ->orderBy('created_at')
            ->get();

        return Inertia::render('Pengaturan/VerifikasiWarga/Index', [
            /*
             * Data penduduk diambil lewat ResidentRepository yang BERLINGKUP,
             * bukan lewat relasi $user->resident.
             *
             * Hari ini izin `pengguna.verifikasi` hanya dimiliki Admin Desa,
             * yang lingkupnya se-desa, sehingga keduanya memberi hasil sama.
             * Bedanya muncul pada hari izin itu diberikan ke peran berwilayah:
             * lewat relasi, seorang Kepala Dusun akan melihat nama, alamat,
             * dan NIK warga di luar dusunnya; lewat repository ia tidak
             * melihat apa pun, dan panelnya tampil tanpa data — gagal
             * tertutup, bukan gagal terbuka (docs/04 §4.1).
             */
            'pending' => $this->withResidents($pending, $residents),
            /*
             * Tanpa memuat relasi `resident`: daftar ini hanya menampilkan
             * nama akun dan hasil pemeriksaannya. Memuat penduduknya berarti
             * membaca tabel dijaga di luar repository, untuk data yang tidak
             * dipakai satu baris pun di layar.
             */
            'recent' => User::query()
                ->whereNotNull('verified_at')
                ->whereHas('roles', fn ($q) => $q->where('code', Role::WARGA))
                ->orderByDesc('verified_at')
                ->limit(10)
                ->get()
                ->map(fn (User $u): array => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'status' => $u->status,
                    'verified_at' => $u->verified_at?->toIso8601String(),
                    'note' => $u->verification_note,
                ])->all(),
        ]);
    }

    /**
     * Foto KTP pemohon, dialirkan lewat permintaan BERIZIN.
     *
     * Tidak pernah ada URL publik maupun pra-tanda-tangan untuk berkas ini:
     * berkasnya di disk privat, dan setiap pembukaan melewati pemeriksaan izin
     * di sini lalu dicatat sebagai pembukaan data pribadi (RULE-07).
     */
    public function document(string $id, AuditLogger $audit): HttpResponse
    {
        $this->authorize('pengguna.verifikasi');

        $user = User::query()->findOrFail($id);
        $path = $user->verification_document_path;

        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        $audit->log('citizen.verification_document_viewed', $user);

        return response(
            Storage::disk('local')->get($path) ?? '',
            200,
            [
                'Content-Type' => Storage::disk('local')->mimeType($path) ?: 'application/octet-stream',
                // `inline` agar petugas melihatnya langsung, bukan mengunduh:
                // berkas KTP yang menumpuk di folder Unduhan komputer balai
                // desa adalah kebocoran yang tidak terlihat siapa pun.
                'Content-Disposition' => 'inline',
            ]
        );
    }

    public function approve(string $id, Request $request, CitizenRegistrationService $registration): RedirectResponse
    {
        $this->authorize('pengguna.verifikasi');

        $user = $this->pendingOrFail($id);
        $officer = $request->user();

        abort_if($officer === null, 403);

        $registration->approve($user, $officer);

        return back()->with('status', 'Akun '.$user->name.' diaktifkan.');
    }

    public function reject(string $id, Request $request, CitizenRegistrationService $registration): RedirectResponse
    {
        $this->authorize('pengguna.verifikasi');

        $user = $this->pendingOrFail($id);
        $officer = $request->user();

        abort_if($officer === null, 403);

        /*
         * Alasan WAJIB dan dibacakan ke warga yang datang bertanya. Penolakan
         * tanpa alasan membuat warga kembali ke loket tanpa tahu apa yang
         * harus ia perbaiki — dan petugas berikutnya pun tidak tahu.
         */
        $data = $request->validate(
            ['reason' => ['required', 'string', 'min:5', 'max:500']],
            attributes: ['reason' => 'alasan'],
        );

        $registration->reject($user, $officer, $data['reason']);

        return back()->with('status', 'Pendaftaran '.$user->name.' ditolak.');
    }

    private function pendingOrFail(string $id): User
    {
        $user = User::query()
            ->where('status', User::STATUS_PENDING)
            ->whereHas('roles', fn ($q) => $q->where('code', Role::WARGA))
            ->find($id);

        /*
         * 404, bukan 403, untuk akun yang bukan warga menunggu verifikasi —
         * termasuk akun petugas. Tanpa pagar ini, rute ini menjadi cara
         * mengaktifkan akun apa pun yang kebetulan berstatus menunggu.
         */
        abort_if($user === null, 404);

        return $user;
    }

    /**
     * @param  Collection<int, User>  $users
     * @return list<array<string, mixed>>
     */
    private function withResidents(Collection $users, ResidentRepository $residents): array
    {
        $byId = $residents->findManyWithContext(
            $users->pluck('resident_id')->filter()->map(fn ($id): string => (string) $id)->values()->all()
        );

        return $users
            ->map(fn (User $u): array => $this->payload($u, $byId->get((string) $u->resident_id)))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function payload(User $user, ?Resident $resident): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'verification_method' => $user->verification_method,
            'has_document' => $user->verification_document_path !== null,
            'created_at' => $user->created_at->toIso8601String(),
            'resident' => $resident === null ? null : [
                'id' => $resident->id,
                /*
                 * Kolom `nik_masked` dibaca langsung, bukan hasil dekripsi
                 * lalu di-masking: layar ini tidak punya alasan menyentuh NIK
                 * terbaca sama sekali. Petugas memutuskan dari nama, alamat,
                 * dan foto KTP; membuka NIK penuh adalah izin tersendiri
                 * (`data_pribadi.lihat_penuh`) dan selalu tercatat (RULE-07).
                 */
                'nik_masked' => $resident->nik_masked,
                'full_name' => $resident->full_name,
                'birth_date' => $resident->birth_date->toDateString(),
                'address' => $resident->family?->address,
                'region' => $resident->region?->displayPath(),
            ],
        ];
    }
}
