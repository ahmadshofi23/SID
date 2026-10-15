<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\LetterRequest;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Satu-satunya pintu ke tabel `letter_requests`.
 *
 * @extends GuardedRepository<LetterRequest>
 */
final class LetterRequestRepository extends GuardedRepository
{
    protected function model(): string
    {
        return LetterRequest::class;
    }

    /**
     * @param  Builder<LetterRequest>  $query
     * @return Builder<LetterRequest>
     */
    protected function applyScope(Builder $query): Builder
    {
        if ($this->scope->userId === null) {
            return $query->whereRaw('1 = 0');
        }

        /*
         * Warga: pengajuan untuk dirinya atau anggota satu KK (RULE-11).
         *
         * Penyaringan berdasarkan `requested_by` saja tidak memadai — surat
         * yang diajukan di kantor desa (walk_in) dibuat oleh akun petugas,
         * sehingga warga tidak akan pernah melihat surat yang ia urus sendiri
         * di loket. Dasarnya adalah PEMOHON-nya, bukan pembuat barisnya.
         */
        if ($this->scope->isFamilyLimited()) {
            $familyId = $this->scope->familyId;
            $residentId = $this->scope->residentId;

            return $query->where(function (Builder $q) use ($familyId, $residentId): void {
                $q->whereHas('resident', function (Builder $r) use ($familyId, $residentId): void {
                    // Keduanya bisa null untuk akun warga yang belum ditautkan
                    // ke data penduduk; '-' menjamin tidak ada yang cocok.
                    $r->where('family_id', $familyId ?? '-')
                        ->orWhere('id', $residentId ?? '-');
                });
            });
        }

        if ($this->scope->isRegionLimited()) {
            $paths = $this->scope->regionPaths ?? [];

            if ($paths === []) {
                return $query->whereRaw('1 = 0');
            }

            return $query->whereHas('resident.region', function (Builder $q) use ($paths): void {
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
     * Antrean pengajuan — FR-SRT-06.
     *
     * @param  array<string, mixed>  $filters
     * @return CursorPaginator<int, LetterRequest>
     */
    public function queue(array $filters = [], int $perPage = 25): CursorPaginator
    {
        $query = $this->scoped()
            ->with(['letterType:id,code,name,sla_days,approval_flow', 'resident:id,full_name,nik_masked,region_id']);

        if (($filters['status'] ?? null) === 'terbuka') {
            $query->whereIn('status', LetterRequest::OPEN_STATUSES);
        } elseif (is_string($filters['status'] ?? null) && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        if (is_string($filters['letter_type_id'] ?? null) && $filters['letter_type_id'] !== '') {
            $query->where('letter_type_id', $filters['letter_type_id']);
        }

        if (($filters['overdue'] ?? false) === true) {
            $query->whereIn('status', LetterRequest::OPEN_STATUSES)
                ->whereNotNull('due_at')
                ->where('due_at', '<', now());
        }

        if (is_string($filters['ticket'] ?? null) && trim($filters['ticket']) !== '') {
            $query->where('ticket_number', 'like', '%'.trim($filters['ticket']).'%');
        }

        /*
         * Diurutkan dari yang PALING LAMA menunggu, bukan yang terbaru.
         *
         * Antrean loket yang menampilkan pengajuan terbaru di atas membuat
         * permintaan lama tenggelam dan melewati SLA tanpa pernah dilihat.
         */
        return $query
            ->orderByRaw('CASE WHEN status IN (?, ?, ?) THEN 0 ELSE 1 END', LetterRequest::OPEN_STATUSES)
            ->orderBy('submitted_at')
            ->orderBy('created_at')
            ->cursorPaginate($perPage);
    }

    public function findByTicket(string $ticket): ?LetterRequest
    {
        return $this->scoped()->where('ticket_number', $ticket)->first();
    }

    /**
     * Pelacakan status oleh warga — SENGAJA TANPA LINGKUP (FR-SRT-13, BR-11).
     *
     * MENGAPA TANPA LINGKUP: pemohon yang dilayani di loket (`walk_in`) tidak
     * punya akun sampai Sprint 6, sehingga tidak ada AccessScope apa pun yang
     * dapat mewakili dirinya. Menyaring di sini berarti BR-11 ("warga dapat
     * memantau status pengajuan") tidak dapat dipenuhi sama sekali bagi
     * mayoritas pemohon hari ini.
     *
     * MENGAPA DUA FAKTOR, BUKAN NOMOR TIKET SAJA: nomor tiket berbentuk
     * `T261015-C507E9` — tanggal ditambah enam karakter acak. Itu dapat
     * dicoba-coba, berbeda dari kode verifikasi surat yang 32 karakter
     * (lihat LetterRepository::findByVerificationCode()). Karena itu tanggal
     * lahir pemohon ikut menjadi bagian KUERI, bukan diperiksa setelah
     * barisnya dimuat: dengan begitu tiket benar + tanggal lahir salah sama
     * sekali tidak pernah memuat pengajuan siapa pun, dan tidak ada jalan
     * untuk membedakan "tiket tidak ada" dari "tanggal lahir salah".
     *
     * Yang membatasi sisanya ada di luar sini: pembatasan laju pada rutenya
     * dan LetterTrackingController yang hanya mengirim ringkasan + riwayat
     * waktu — tanpa NIK dan tanpa isi formulir.
     *
     * @param  string  $birthDate  Tanggal lahir pemohon, format Y-m-d.
     */
    public function findByTicketForTracking(string $ticket, string $birthDate): ?LetterRequest
    {
        return LetterRequest::query()
            ->with([
                // Seluruh kolom jenis surat, bukan sebagian: jumlah tahap
                // pemeriksaan dibaca dari `approval_flow`, dan pemilihan
                // kolom yang hemat di sini membuat verificationStepCount()
                // gagal pada null.
                'letterType',
                'resident:id,full_name,birth_date',
                'logs',
                'letter:id,request_id,letter_number,issued_at',
            ])
            ->where('ticket_number', $ticket)
            ->whereHas('resident', fn (Builder $q): Builder => $q->where('birth_date', $birthDate))
            ->first();
    }

    /** Jumlah per status, untuk kartu ringkasan di dasbor. */
    /** @return array<string, int> */
    public function countByStatus(): array
    {
        /** @var array<string, int> */
        return $this->scoped()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    public function countOverdue(): int
    {
        return $this->scoped()
            ->whereIn('status', LetterRequest::OPEN_STATUSES)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->count();
    }

    /**
     * Pengajuan terbuka milik pemohon untuk jenis surat yang sama.
     *
     * Dipakai untuk mencegah pengajuan ganda (FR-SRT-04): warga yang menekan
     * tombol kirim dua kali, atau mengajukan ulang karena belum dibalas,
     * tidak boleh menumpuk antrean petugas.
     */
    public function openRequestFor(string $residentId, string $letterTypeId): ?LetterRequest
    {
        return $this->scoped()
            ->where('resident_id', $residentId)
            ->where('letter_type_id', $letterTypeId)
            ->whereIn('status', LetterRequest::OPEN_STATUSES)
            ->first();
    }

    /**
     * Pembuatan pengajuan.
     *
     * TIDAK melalui scoped(): baris belum ada, jadi tak ada yang bisa
     * disaring. Kelayakan pemohon (apakah pengguna ini boleh mengajukan
     * untuk penduduk tersebut) diperiksa di LetterRequestService dengan
     * ResidentRepository — yang sudah berlingkup.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): LetterRequest
    {
        return LetterRequest::query()->create($attributes);
    }
}
