<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Letter;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Satu-satunya pintu ke tabel `letters`.
 *
 * @extends GuardedRepository<Letter>
 */
final class LetterRepository extends GuardedRepository
{
    protected function model(): string
    {
        return Letter::class;
    }

    /**
     * @param  Builder<Letter>  $query
     * @return Builder<Letter>
     */
    protected function applyScope(Builder $query): Builder
    {
        if ($this->scope->userId === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->scope->isFamilyLimited()) {
            $familyId = $this->scope->familyId;
            $residentId = $this->scope->residentId;

            return $query->whereHas('request.resident',
                function (Builder $r) use ($familyId, $residentId): void {
                    $r->where('family_id', $familyId ?? '-')
                        ->orWhere('id', $residentId ?? '-');
                });
        }

        if ($this->scope->isRegionLimited()) {
            $paths = $this->scope->regionPaths ?? [];

            if ($paths === []) {
                return $query->whereRaw('1 = 0');
            }

            return $query->whereHas('request.resident.region',
                function (Builder $q) use ($paths): void {
                    $q->where(function (Builder $inner) use ($paths): void {
                        foreach ($paths as $path) {
                            $inner->orWhere('path', $path)
                                ->orWhere('path', 'like', $path.'/%');
                        }
                    });
                });
        }

        return $query;
    }

    /**
     * Arsip surat terbit — FR-SRT-13.
     *
     * @param  array<string, mixed>  $filters
     * @return CursorPaginator<int, Letter>
     */
    public function archive(array $filters = [], int $perPage = 25): CursorPaginator
    {
        $query = $this->scoped()->with([
            'letterType:id,code,name',
            'request:id,ticket_number,resident_id',
            'request.resident:id,full_name,nik_masked',
        ]);

        if (is_string($filters['term'] ?? null) && trim($filters['term']) !== '') {
            $query->where('letter_number', 'like', '%'.trim($filters['term']).'%');
        }

        if (is_string($filters['letter_type_id'] ?? null) && $filters['letter_type_id'] !== '') {
            $query->where('letter_type_id', $filters['letter_type_id']);
        }

        if (is_string($filters['status'] ?? null) && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        if (is_string($filters['from'] ?? null) && $filters['from'] !== '') {
            $query->where('issued_at', '>=', $filters['from'].' 00:00:00');
        }

        if (is_string($filters['to'] ?? null) && $filters['to'] !== '') {
            $query->where('issued_at', '<=', $filters['to'].' 23:59:59');
        }

        return $query->orderByDesc('issued_at')->cursorPaginate($perPage);
    }

    public function countIssuedThisMonth(): int
    {
        return $this->scoped()
            ->where('status', Letter::STATUS_VALID)
            ->where('issued_at', '>=', now()->startOfMonth())
            ->count();
    }

    /**
     * Verifikasi surat dari kode QR — SENGAJA TANPA LINGKUP.
     *
     * Verifikasi dipakai pihak luar (bank, sekolah, kantor kecamatan) yang
     * tidak punya akun di sistem ini; menyaring berdasarkan wilayah akan
     * membuat fitur ini tidak berfungsi sama sekali.
     *
     * Yang membuatnya aman: kode verifikasi 32 karakter acak tidak dapat
     * diterka, dan pemanggilnya (LetterVerificationController) hanya
     * menampilkan ringkasan tidak sensitif — nomor surat, jenis, nama
     * terpotong, tanggal, dan status. BUKAN snapshot atau NIK.
     */
    public function findByVerificationCode(string $code): ?Letter
    {
        return Letter::query()
            ->with(['letterType:id,code,name', 'request:id,resident_id', 'request.resident:id,full_name'])
            ->where('verification_code', $code)
            ->first();
    }

    /**
     * Penerbitan surat.
     *
     * TIDAK melalui scoped() — baris belum ada. Kewenangan penerbitan
     * diperiksa di LetterRequestService (peran langkah + RULE-06).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Letter
    {
        return Letter::query()->create($attributes);
    }
}
