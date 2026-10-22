<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Complaint;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Satu-satunya pintu ke tabel `complaints`.
 *
 * @extends GuardedRepository<Complaint>
 */
final class ComplaintRepository extends GuardedRepository
{
    protected function model(): string
    {
        return Complaint::class;
    }

    /**
     * @param  Builder<Complaint>  $query
     * @return Builder<Complaint>
     */
    protected function applyScope(Builder $query): Builder
    {
        /*
         * Anonim tidak melihat satu baris pun lewat jalur ini. Dua jalur
         * publik yang sah punya metodenya sendiri di bawah dan keduanya
         * menjelaskan mengapa: findByTrackingCode() (pelapor memegang kode
         * rahasianya) dan published() (ringkasan yang memang disetujui terbit).
         */
        if ($this->scope->userId === null) {
            return $query->whereRaw('1 = 0');
        }

        /*
         * Warga: HANYA pengaduan yang ia sendiri laporkan — bukan pengaduan
         * satu KK seperti pada surat.
         *
         * Perbedaan ini sengaja. RULE-11 menyatukan keluarga karena surat
         * memang diurus bersama: ayah mengambil SKTM anaknya. Pengaduan tidak
         * begitu. Laporan tentang suami yang mengambil bantuan sosial atas nama
         * istrinya, atau tentang perlakuan satu anggota keluarga, akan terbuka
         * justru kepada orang yang diadukan bila lingkupnya keluarga.
         *
         * Akibatnya yang harus terlihat di antarmuka: pengaduan ANONIM tidak
         * terlihat oleh pelapornya sendiri di sini, karena RULE-09 melarang
         * menyimpan tautan apa pun ke akunnya. Jalur pelapor anonim adalah
         * kode pelacakan — sama seperti nomor tiket bagi pemohon loket
         * (KEP-014 §5). Halaman "pengaduan saya" WAJIB mengatakan itu, agar
         * warga tidak menyangka laporannya hilang.
         */
        if ($this->scope->isFamilyLimited()) {
            return $query->where('reporter_user_id', $this->scope->userId);
        }

        /*
         * Kepala Dusun / RT: hanya pengaduan di wilayah yang ditugaskan.
         *
         * Pengaduan TANPA wilayah (`region_id` NULL) tidak terlihat olehnya.
         * Ini pilihan yang gagal-tertutup dan memang dikehendaki: pengaduan
         * yang pelapornya tidak menyebut lokasi bisa menyangkut siapa saja di
         * desa — termasuk perangkat dusun yang akan membacanya. Pengaduan
         * seperti itu ditriase Kasi atau Admin Desa lebih dulu, yang lingkupnya
         * memang seluruh desa.
         */
        if ($this->scope->isRegionLimited()) {
            $paths = $this->scope->regionPaths ?? [];

            if ($paths === []) {
                return $query->whereRaw('1 = 0');
            }

            return $query->whereHas('region', function (Builder $q) use ($paths): void {
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
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Complaint
    {
        return Complaint::query()->create($attributes);
    }

    /**
     * Antrean pengaduan — UC-14.
     *
     * @param  array<string, mixed>  $filters
     * @return CursorPaginator<int, Complaint>
     */
    public function queue(array $filters = [], int $perPage = 25): CursorPaginator
    {
        $query = $this->scoped()->with([
            'region:id,name,path',
            'assignee:id,name',
        ]);

        if (($filters['status'] ?? null) === 'terbuka') {
            $query->whereIn('status', Complaint::OPEN_STATUSES);
        } elseif (is_string($filters['status'] ?? null) && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        if (is_string($filters['category_code'] ?? null) && $filters['category_code'] !== '') {
            $query->where('category_code', $filters['category_code']);
        }

        if (is_string($filters['priority'] ?? null) && $filters['priority'] !== '') {
            $query->where('priority', $filters['priority']);
        }

        if (is_string($filters['assigned_to'] ?? null) && $filters['assigned_to'] !== '') {
            $query->where('assigned_to', $filters['assigned_to']);
        }

        if (($filters['overdue'] ?? false) === true) {
            $this->whereOverdue($query);
        }

        if (is_string($filters['q'] ?? null) && trim($filters['q']) !== '') {
            $term = trim($filters['q']);

            $query->where(function (Builder $q) use ($term): void {
                $q->where('ticket_number', 'like', '%'.$term.'%')
                    ->orWhere('title', 'like', '%'.$term.'%');
            });
        }

        /*
         * Diurutkan dari yang PALING LAMA menunggu, dengan prioritas tinggi
         * di depan. Alasannya sama dengan antrean surat: daftar yang menaruh
         * pengaduan terbaru di atas membuat laporan lama tenggelam dan
         * melewati SLA tanpa pernah dibaca — dan pengaduan yang didiamkan
         * adalah alasan paling umum warga berhenti melapor sama sekali.
         */
        return $query
            ->orderByRaw('CASE WHEN status IN (?, ?, ?) THEN 0 ELSE 1 END', Complaint::OPEN_STATUSES)
            ->orderByRaw("FIELD(priority, 'tinggi', 'normal', 'rendah')")
            ->orderBy('created_at')
            ->cursorPaginate($perPage);
    }

    /**
     * Pelacakan oleh pelapor — SENGAJA TANPA LINGKUP (FR-PGD-02).
     *
     * MENGAPA TANPA LINGKUP: pelapor anonim tidak punya AccessScope yang dapat
     * mewakilinya, dan memang tidak boleh punya. Menyaring di sini berarti
     * janji FR-PGD-02 ("kode pelacakan tetap diberikan") tidak dapat ditepati.
     *
     * MENGAPA SATU FAKTOR CUKUP DI SINI, berbeda dari pelacakan surat yang
     * menuntut tiket + tanggal lahir: `tracking_code` adalah rahasia yang
     * DIBANGKITKAN sistem dengan entropi penuh, bukan nomor administratif
     * bertanggal yang beredar di berkas desa. Tidak ada faktor kedua yang
     * dapat diminta dari pelapor anonim tanpa menghapus keanoniman itu
     * sendiri — tanggal lahir adalah identitas.
     *
     * Yang membatasi sisanya ada di luar sini: pembatasan laju pada rutenya,
     * dan pengendali yang hanya mengirim status + tindak lanjut yang memang
     * ditandai terlihat oleh pelapor.
     */
    public function findByTrackingCode(string $code): ?Complaint
    {
        return Complaint::query()
            ->with(['region:id,name', 'actions' => fn ($q) => $q->where('is_visible_to_reporter', true)])
            ->where('tracking_code', $code)
            ->first();
    }

    /**
     * Ringkasan pengaduan selesai untuk portal publik — FR-PGD-07.
     *
     * TANPA LINGKUP dan itu memang maksudnya: baris yang keluar dari sini
     * sudah ditandai `is_published` oleh petugas, satu kasus demi satu kasus
     * (opt-in). Yang menjaga privasinya bukan lingkup pengguna melainkan dua
     * syarat di kueri ini — sudah selesai DAN sudah disetujui terbit — serta
     * kolom yang dipilih: tanpa identitas pelapor, tanpa nomor telepon,
     * tanpa titik koordinat yang menunjuk rumah orang.
     *
     * @return Collection<int, Complaint>
     */
    public function published(int $limit = 10): Collection
    {
        return Complaint::query()
            ->select([
                'id', 'ticket_number', 'category_code', 'title',
                'status', 'resolution', 'resolved_at', 'created_at',
            ])
            ->where('is_published', true)
            ->where('status', Complaint::STATUS_DONE)
            ->orderByDesc('resolved_at')
            ->limit($limit)
            ->get();
    }

    public function findByTicket(string $ticket): ?Complaint
    {
        return $this->scoped()->where('ticket_number', $ticket)->first();
    }

    /** Jumlah per status, untuk kartu ringkasan dashboard (FR-LAP-01). */
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

    public function countOpen(): int
    {
        return $this->scoped()->whereIn('status', Complaint::OPEN_STATUSES)->count();
    }

    public function countOverdue(): int
    {
        return $this->whereOverdue($this->scoped())->count();
    }

    /**
     * Pengaduan terbuka yang sudah melewati tenggatnya.
     *
     * Satu tempat untuk definisi "terlambat", dipakai antrean, dashboard, dan
     * perintah eskalasi. Tenggat yang berlaku bergantung status (KEP-018):
     * selagi `baru` yang ditunggu adalah triase, setelahnya penyelesaian.
     * Menulis ulang ekspresi ini di tiga tempat berarti tiga angka
     * "pengaduan terlambat" yang berbeda di tiga layar.
     *
     * @param  Builder<Complaint>  $query
     * @return Builder<Complaint>
     */
    public function whereOverdue(Builder $query): Builder
    {
        $now = now();

        return $query->where(function (Builder $q) use ($now): void {
            $q->where(function (Builder $triase) use ($now): void {
                $triase->where('status', Complaint::STATUS_NEW)
                    ->whereNotNull('triage_due_at')
                    ->where('triage_due_at', '<', $now);
            })->orWhere(function (Builder $selesai) use ($now): void {
                $selesai->whereIn('status', [
                    Complaint::STATUS_ACCEPTED,
                    Complaint::STATUS_IN_PROGRESS,
                ])
                    ->whereNotNull('due_at')
                    ->where('due_at', '<', $now);
            });
        });
    }

    /**
     * Pengaduan terlambat untuk perintah eskalasi — TANPA LINGKUP.
     *
     * Dipanggil dari perintah konsol yang berjalan sebagai sistem, bukan atas
     * nama seorang pengguna; menyaringnya dengan lingkup pengguna anonim
     * (`1 = 0`) akan membuat eskalasi tidak pernah menemukan apa pun.
     *
     * @return Collection<int, Complaint>
     */
    public function overdueForEscalation(): Collection
    {
        return $this->whereOverdue(Complaint::query())
            ->where('priority', '!=', Complaint::PRIORITY_HIGH)
            ->with('assignee:id,name')
            ->get();
    }

    public function trackingCodeExists(string $code): bool
    {
        return Complaint::query()->where('tracking_code', $code)->exists();
    }

    public function ticketExists(string $ticket): bool
    {
        return Complaint::query()->where('ticket_number', $ticket)->exists();
    }

    /**
     * Jumlah pengaduan dari satu jaringan dalam jendela waktu — FR-PGD-08.
     *
     * Yang diterima adalah SIDIK JARI ber-kunci alamat IP, bukan alamatnya:
     * alamat mentah tidak pernah disimpan maupun dibandingkan di sini. Lihat
     * keterangan kolom `submitted_ip_hash` pada migrasinya.
     *
     * TANPA LINGKUP dengan sengaja: pembatasan laju harus menghitung SELURUH
     * pengaduan dari jaringan itu, termasuk yang tidak terlihat oleh siapa pun
     * yang sedang memeriksanya. Yang dikembalikan hanya sebuah angka, bukan
     * isi pengaduan — jadi tidak ada data yang bocor lewat jalur ini.
     */
    public function countFromIpSince(string $ipHash, \DateTimeInterface $since): int
    {
        return Complaint::query()
            ->where('submitted_ip_hash', $ipHash)
            ->where('created_at', '>=', $since)
            ->count();
    }
}
