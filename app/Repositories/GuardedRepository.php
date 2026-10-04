<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Auth\AccessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Basis lapis repository untuk tabel yang dijaga (config/sid.php guarded_tables).
 *
 * MENGAPA LAPIS INI ADA (docs/04-arsitektur.md §4.1, KEP-002):
 * PostgreSQL punya Row-Level Security sebagai jaring pengaman terakhir; MySQL
 * tidak. Maka satu kueri yang lupa menyaring wilayah akan membocorkan NIK
 * SELURUH warga desa. Kelas ini memastikan penyaringan itu tidak bisa dilupakan:
 * tidak ada jalan mendapatkan builder tanpa lebih dulu menyerahkan AccessScope.
 *
 * Aturan yang ditegakkan di luar kelas ini:
 *   - tests/Architecture/RepositoryBoundaryTest.php menolak akses langsung ke
 *     tabel yang dijaga dari Controller / Command / Job.
 *
 * @template TModel of Model
 */
abstract class GuardedRepository
{
    public function __construct(protected readonly AccessScope $scope) {}

    /** @return class-string<TModel> */
    abstract protected function model(): string;

    /**
     * Terapkan pembatasan lingkup. Setiap turunan WAJIB mengimplementasikan
     * dan tidak boleh mengembalikan builder tanpa penyaringan.
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    abstract protected function applyScope(Builder $query): Builder;

    /**
     * Satu-satunya pintu untuk mendapatkan query yang sudah dibatasi.
     *
     * @return Builder<TModel>
     */
    final protected function scoped(): Builder
    {
        $model = $this->model();

        return $this->applyScope($model::query());
    }

    /**
     * Ambil satu objek dalam lingkup pengguna.
     *
     * Mengembalikan null (yang dipetakan menjadi HTTP 404 oleh pemanggil)
     * untuk objek DI LUAR lingkup — bukan 403. Lihat docs/06-api-spec.md §4:
     * 403 akan memastikan bagi penyerang bahwa objek itu memang ada.
     *
     * @return TModel|null
     */
    public function find(string $id): ?Model
    {
        return $this->scoped()->whereKey($id)->first();
    }

    /**
     * @return TModel
     */
    public function findOrFail(string $id): Model
    {
        $record = $this->find($id);

        if ($record === null) {
            abort(404);
        }

        return $record;
    }

    /**
     * Pagar terakhir: pastikan turunan tidak mengembalikan builder polos.
     *
     * @param  Builder<TModel>  $query
     * @param  Builder<TModel>  $original
     */
    final protected function assertScopeApplied(Builder $query, Builder $original): void
    {
        if ($query === $original && $this->scope->userId === null) {
            throw new LogicException(
                static::class.'::applyScope() tidak menerapkan pembatasan apa pun '
                .'untuk pengguna anonim. Ini akan membocorkan data.'
            );
        }
    }
}
