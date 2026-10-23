<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\AccessScope;
use App\Models\User;
use App\Models\VillageProfile;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Data yang dibagikan ke SETIAP halaman Inertia — KEP-008.
 *
 * Prinsip yang dijaga di sini: yang dikirim ke peramban hanyalah apa yang
 * memang perlu ditampilkan. Daftar izin dikirim agar menu yang tidak relevan
 * tidak muncul — itu kenyamanan, bukan keamanan. Penegakannya tetap di
 * middleware `izin:` dan lapis repository (docs/04-arsitektur.md §4.1).
 *
 * Yang TIDAK PERNAH dikirim: NIK, nomor KK, atau data pribadi lain yang belum
 * ter-masking (RULE-07).
 */
final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();
        $village = VillageProfile::current();

        return [
            ...parent::share($request),

            'village' => [
                'name' => $village->name ?? '',
                'district' => $village->district ?? '',
                'regency' => $village->regency ?? '',
                'province' => $village->province ?? '',
            ],

            'auth' => [
                'user' => $user === null ? null : $this->userPayload($user),
            ],

            /*
             * Jumlah notifikasi belum dibaca untuk lonceng di setiap layar.
             *
             * Closure, bukan nilai: ia menjadi satu kueri COUNT pada SETIAP
             * permintaan Inertia, dan pengguna yang belum masuk tidak perlu
             * membayarnya sama sekali.
             */
            'notifikasi_belum_dibaca' => fn (): int => $user === null
                ? 0
                : $user->unreadNotifications()->count(),

            // Lazy: hanya dihitung saat benar-benar ada pesan.
            'flash' => fn (): array => [
                'status' => $request->session()->get('status'),
                'warning' => $request->session()->get('warning'),
                'error' => $request->session()->get('error'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user): array
    {
        $scope = AccessScope::forUser($user);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'roles' => $scope->roles,
            'role_labels' => $user->roles->pluck('name')->values()->all(),
            'permissions' => $scope->permissions,
            'region_limited' => $scope->isRegionLimited(),
            'region_assigned' => $scope->regionPaths !== [],
            /*
             * Lingkupnya sesempit keluarganya sendiri — yaitu warga.
             *
             * Dipakai menu untuk menyembunyikan ANTREAN PETUGAS, yang sejak
             * Sprint 7 dijaga middleware `petugas` (EnsureStaffQueue). Tanpa
             * ini warga tetap melihat "Antrean Surat" dan "Antrean Pengaduan"
             * di menunya — ia memang memegang `surat.lihat` dan
             * `pengaduan.lihat` — lalu menerima 403 ketika menekannya. Menu
             * yang menuntun ke penolakan lebih buruk daripada menu yang tidak
             * ada: ia membuat orang menyimpulkan sistemnya rusak (KEP-012).
             */
            'family_limited' => $scope->isFamilyLimited(),
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
        ];
    }
}
