<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\AccessScope;
use App\Models\User;
use App\Models\VillageProfile;
use App\Repositories\FamilyRepository;
use App\Repositories\ResidentRepository;
use App\Support\Pii\PiiCipher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use PragmaRX\Google2FA\Google2FA;

final class SidServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PiiCipher::class, fn () => new PiiCipher);
        $this->app->singleton(Google2FA::class, fn () => new Google2FA);

        /*
         * AccessScope diikat per-permintaan dari pengguna yang sedang masuk.
         * Repository WAJIB mendapatkannya lewat container, bukan membuat
         * sendiri — sehingga tidak ada jalan membuat repository tanpa lingkup.
         */
        $this->app->scoped(
            AccessScope::class,
            fn () => ($user = Auth::user()) instanceof User
                ? AccessScope::forUser($user)
                : AccessScope::guest()
        );

        $this->app->bind(ResidentRepository::class, fn ($app) => new ResidentRepository(
            $app->make(AccessScope::class)
        ));

        $this->app->bind(FamilyRepository::class, fn ($app) => new FamilyRepository(
            $app->make(AccessScope::class)
        ));
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        /*
         * Gate tunggal untuk seluruh izin granular, bersumber dari AccessScope.
         * Satu titik penegakan (docs/04 §6) — bukan tersebar di kontroler.
         */
        Gate::before(function (User $user, string $ability) {
            return AccessScope::forUser($user)->can($ability) ? true : null;
        });

        /*
         * Identitas desa dipakai hampir setiap halaman (kop, judul, footer).
         *
         * Memakai composer, BUKAN View::share(): share akan mengeksekusi kueri
         * saat provider boot — termasuk ketika `migrate:fresh` dijalankan pada
         * basis data yang tabelnya belum ada, sehingga perintah migrasi gagal.
         * Composer hanya berjalan ketika sebuah view benar-benar dirender.
         */
        View::composer('*', function ($view): void {
            $view->with('village', VillageProfile::current());
        });

        $this->logSlowQueries();
    }

    /**
     * Peringatan dini atas kueri lambat. Tanpa pg_trgm, pencarian penduduk
     * memakai LIKE '%...%' yang baik di 5.000 baris tetapi akan memburuk bila
     * data tumbuh di luar perkiraan — lebih baik terlihat di log sejak awal
     * daripada menjadi keluhan "sistem lambat" setahun kemudian.
     */
    private function logSlowQueries(): void
    {
        if ($this->app->runningUnitTests()) {
            return;
        }

        DB::whenQueryingForLongerThan(1000, function ($connection, $event): void {
            Log::warning('Kueri lambat (> 1 detik)', [
                'sql' => $event->sql,   // binding TIDAK dicatat: dapat memuat NIK
                'time_ms' => $event->time,
            ]);
        });
    }
}
