<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\LetterRequest;
use App\Models\Role;
use App\Support\VillageTime;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

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
     * Tren layanan 12 bulan — FR-LAP-01.
     *
     * DUA deret dalam satu kueri per deret, bukan satu kueri per bulan: dua
     * belas bulan × dua deret berarti 24 perjalanan ke basis data untuk satu
     * kartu dashboard, dan dashboard adalah halaman yang dibuka paling sering.
     *
     * Dikelompokkan pada ZONA DESA, bukan UTC (KEP-010). Pengajuan yang masuk
     * pukul 08.00 WIB tanggal 1 tersimpan sebagai 01.00 UTC tanggal 1 — masih
     * sama; tetapi yang masuk pukul 06.00 WIB tanggal 1 tersimpan sebagai
     * 23.00 UTC tanggal SEBELUMNYA, dan dikelompokkan mentah ia akan jatuh di
     * bulan yang salah setiap awal bulan.
     *
     * @return list<array{bulan: string, label: string, masuk: int, selesai: int}>
     */
    public function monthlyTrend(int $months = 12): array
    {
        $zone = VillageTime::zone();
        $since = VillageTime::local(now())->startOfMonth()->subMonths($months - 1)->utc();

        $masuk = $this->countByMonth('submitted_at', $since, $zone);
        $selesai = $this->countByMonth('completed_at', $since, $zone);

        $result = [];
        $cursor = VillageTime::local($since);

        for ($i = 0; $i < $months; $i++) {
            $key = $cursor->format('Y-m');

            $result[] = [
                'bulan' => $key,
                'label' => $cursor->locale('id')->translatedFormat('M y'),
                'masuk' => $masuk[$key] ?? 0,
                'selesai' => $selesai[$key] ?? 0,
            ];

            $cursor = $cursor->copy()->addMonth();
        }

        return $result;
    }

    /**
     * @return array<string, int> "Y-m" => jumlah
     */
    private function countByMonth(string $column, Carbon $since, string $zone): array
    {
        /** @var array<string, int> */
        return $this->scoped()
            ->whereNotNull($column)
            ->where($column, '>=', $since)
            ->toBase()
            ->selectRaw(
                "DATE_FORMAT(CONVERT_TZ({$column}, '+00:00', ?), '%Y-%m') AS bulan, COUNT(*) AS jumlah",
                [$this->tzOffset($zone)]
            )
            ->groupBy('bulan')
            ->pluck('jumlah', 'bulan')
            ->map(fn ($v): int => (int) $v)
            ->all();
    }

    /**
     * Zona desa sebagai selisih "+07:00".
     *
     * MySQL `CONVERT_TZ` menerima nama zona seperti 'Asia/Jakarta' HANYA bila
     * tabel zona waktunya sudah dimuat (`mysql_tzinfo_to_sql`), dan pada
     * pemasangan bawaan ia TIDAK dimuat — hasilnya NULL tanpa galat, sehingga
     * seluruh grafik tren menjadi kosong tanpa ada yang mengerti mengapa.
     * Selisih numerik selalu bekerja.
     *
     * Dihitung dari tanggal hari ini, bukan ditetapkan di kode: Indonesia
     * memang tidak memakai waktu musim panas, tetapi desa di WITA/WIT
     * memakai selisih yang berbeda.
     */
    private function tzOffset(string $zone): string
    {
        return now()->setTimezone($zone)->format('P');
    }

    /**
     * Ketepatan SLA layanan — FR-LAP-01, FR-LAP-05.
     *
     * Dihitung dari surat yang SUDAH SELESAI saja. Memasukkan yang masih
     * berjalan akan membuat angka "rata-rata waktu selesai" turun setiap kali
     * ada pengajuan baru masuk — tampak membaik justru ketika antrean
     * menumpuk.
     *
     * @return array{total: int, rata_jam: float|null, tepat_waktu: int, persen_tepat: float|null}
     */
    public function slaPerformance(int $months = 12): array
    {
        $since = VillageTime::local(now())->startOfMonth()->subMonths($months - 1)->utc();

        $row = $this->scoped()
            ->whereNotNull('completed_at')
            ->whereNotNull('submitted_at')
            ->where('completed_at', '>=', $since)
            ->toBase()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, submitted_at, completed_at)) AS rata_menit')
            ->selectRaw('SUM(CASE WHEN due_at IS NULL OR completed_at <= due_at THEN 1 ELSE 0 END) AS tepat')
            ->first();

        $total = (int) ($row->total ?? 0);
        $tepat = (int) ($row->tepat ?? 0);

        return [
            'total' => $total,
            'rata_jam' => $total === 0 ? null : round(((float) $row->rata_menit) / 60, 1),
            'tepat_waktu' => $tepat,
            'persen_tepat' => $total === 0 ? null : round($tepat / $total * 100, 1),
        ];
    }

    /**
     * Pengajuan yang MENUNGGU TINDAKAN PENGGUNA INI — FR-LAP-02, docs/07 §5.4.
     *
     * Inti dari keputusan desain dashboard pimpinan: yang ditampilkan adalah
     * pekerjaan yang menunggu DIRINYA, bukan sekadar angka. Tanpa ini,
     * persetujuan menumpuk dan sasaran O-2 (SLA 1 jam) tidak tercapai.
     *
     * Penyaringan kewenangan dilakukan DI PHP, bukan di SQL, dan itu disengaja:
     * peran yang berwenang per tahap tersimpan di dalam JSON `approval_flow`
     * milik jenis surat, dan menuliskannya sebagai kueri JSON MySQL akan
     * menjadi ekspresi yang tidak dapat dibaca siapa pun DAN menduplikasi
     * aturan yang sudah ada di LetterType::rolesForStep(). Aturan kewenangan
     * yang ditulis dua kali akan berbeda pelan-pelan — biasanya pada sisi yang
     * lebih longgar.
     *
     * Jumlah baris yang disaring terbatas: hanya pengajuan yang masih terbuka,
     * dan antrean desa berukuran puluhan, bukan ribuan.
     *
     * @return list<LetterRequest>
     */
    public function awaitingMyAction(int $limit = 5): array
    {
        $scope = $this->scope;
        $isAdmin = $scope->hasRole(Role::ADMIN_DESA);

        $candidates = $this->scoped()
            ->whereIn('status', [LetterRequest::STATUS_SUBMITTED, LetterRequest::STATUS_VERIFIED])
            ->with(['letterType', 'resident:id,full_name,nik_masked'])
            ->orderBy('submitted_at')
            ->limit(100)
            ->get();

        $mine = $candidates->filter(function (LetterRequest $request) use ($scope, $isAdmin): bool {
            /*
             * Status `diverifikasi` berarti menunggu PENERBITAN, bukan
             * pemeriksaan lagi — dan itu kewenangan Kepala Desa atau Admin
             * Desa (lihat LetterRequestService::guardIssueAuthority()).
             */
            if ($request->status === LetterRequest::STATUS_VERIFIED) {
                return $isAdmin || $scope->hasRole(Role::KEPALA_DESA);
            }

            if ($isAdmin) {
                return true;
            }

            foreach ($request->letterType->rolesForStep($request->current_step) as $role) {
                if ($scope->hasRole($role)) {
                    return true;
                }
            }

            return false;
        });

        return $mine->take($limit)->values()->all();
    }

    /**
     * Rekap layanan surat per JENIS — FR-LAP-05.
     *
     * Volume, rata-rata waktu penyelesaian, dan ketepatan SLA per jenis surat
     * dalam satu kueri agregat.
     *
     * Rata-rata dihitung HANYA dari yang sudah selesai, dengan alasan yang
     * sama seperti slaPerformance(): memasukkan yang masih berjalan membuat
     * angkanya membaik justru ketika antrean menumpuk. Jenis surat yang belum
     * punya satu pun surat selesai tetap muncul dengan rata-rata null — bukan
     * nol, yang akan terbaca sebagai "selesai seketika".
     *
     * @return list<array{code: string, name: string, total: int, selesai: int, ditolak: int, rata_jam: float|null, persen_tepat: float|null}>
     */
    public function serviceRecap(?Carbon $from = null, ?Carbon $to = null): array
    {
        /*
         * Nama kolom DIKUALIFIKASI dengan nama tabelnya, bukan ditulis polos.
         *
         * Kueri ini menggabung `letter_types`, dan KEDUA tabel punya
         * `created_at`. Tanpa kualifikasi MySQL menolaknya sebagai ambigu —
         * yang di layar petugas berupa HTTP 500 pada halaman laporan.
         */
        $rows = $this->scoped()
            ->when($from !== null, fn (Builder $q) => $q->where('letter_requests.created_at', '>=', $from))
            ->when($to !== null, fn (Builder $q) => $q->where('letter_requests.created_at', '<=', $to))
            ->toBase()
            ->join('letter_types', 'letter_requests.letter_type_id', '=', 'letter_types.id')
            ->groupBy('letter_types.code', 'letter_types.name', 'letter_types.sort_order')
            ->orderBy('letter_types.sort_order')
            ->selectRaw('letter_types.code AS code, letter_types.name AS name')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN letter_requests.status = ? THEN 1 ELSE 0 END) AS selesai', [LetterRequest::STATUS_ISSUED])
            ->selectRaw('SUM(CASE WHEN letter_requests.status = ? THEN 1 ELSE 0 END) AS ditolak', [LetterRequest::STATUS_REJECTED])
            ->selectRaw('AVG(CASE WHEN completed_at IS NOT NULL AND submitted_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, submitted_at, completed_at) END) AS rata_menit')
            ->selectRaw('SUM(CASE WHEN completed_at IS NOT NULL THEN 1 ELSE 0 END) AS berakhir')
            ->selectRaw('SUM(CASE WHEN completed_at IS NOT NULL AND (due_at IS NULL OR completed_at <= due_at) THEN 1 ELSE 0 END) AS tepat')
            ->get();

        return $rows->map(function (object $row): array {
            $berakhir = (int) $row->berakhir;

            return [
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'total' => (int) $row->total,
                'selesai' => (int) $row->selesai,
                'ditolak' => (int) $row->ditolak,
                'rata_jam' => $row->rata_menit === null
                    ? null
                    : round(((float) $row->rata_menit) / 60, 1),
                'persen_tepat' => $berakhir === 0
                    ? null
                    : round((int) $row->tepat / $berakhir * 100, 1),
            ];
        })->all();
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
