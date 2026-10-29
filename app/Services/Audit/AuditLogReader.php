<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Pembacaan audit log — FR-ADM-05, UC-19.
 *
 * MENGAPA KELAS TERSENDIRI, BUKAN METODE DI AuditLogger
 * -----------------------------------------------------
 * `AuditLogger` adalah satu-satunya pintu MENULIS rantai hash: ia mengunci
 * baris mutex, membaca ujung rantai, dan menghitung `row_hash` (KEP-020).
 * Membaca tidak menyentuh satu pun dari itu. Menaruh keduanya di satu kelas
 * berarti setiap pembacaan membawa serta dependensi penulisan — dan, lebih
 * buruk, membuat `AuditLogger` tampak seperti tempat yang wajar untuk
 * menambahkan kueri baru, padahal setiap tambahan di sana harus dipikirkan
 * terhadap penguncian.
 *
 * MENGAPA BUKAN App\Repositories
 * ------------------------------
 * Lapis repository di proyek ini ada untuk tabel yang MENUNTUT pembatasan
 * wilayah/keluarga (KEP-002): `residents`, `families`, `letter_requests`,
 * `letters`, `complaints`. `audit_logs` justru sebaliknya — audit yang
 * disaring menurut wilayah pembacanya tidak bernilai sebagai bukti, dan
 * izinnya (`audit.lihat`) memang hanya dipegang Admin Desa, Kepala Desa, dan
 * Pengawas. `tests/Architecture/RepositoryBoundaryTest.php` karena itu tidak
 * mencakup tabel ini.
 *
 * SETIAP KUERI DI SINI DIUKUR PADA 1,5 JUTA BARIS
 * -----------------------------------------------
 * Tabel ini adalah tabel terbesar di sistem (docs/23 §4), dan halaman ini
 * satu-satunya yang membacanya. Angka di bawah berasal dari `sid_desa_volume`
 * dengan 1.500.081 baris; keterangan per metode menyebutkan yang relevan.
 */
final class AuditLogReader
{
    /**
     * Berapa baris per halaman.
     *
     * Bukan 25 seperti antrean lain: baris audit dibaca dengan MENYAPU, bukan
     * dengan membuka satu per satu, dan sapuan 50 baris per halaman separuh
     * lebih sedikit klik untuk pemeriksa yang menelusuri satu hari kerja.
     */
    public const PER_PAGE = 50;

    /**
     * Pencarian audit log — FR-ADM-05.
     *
     * URUTANNYA `created_at DESC, id DESC`, DAN ITU BUKAN SELERA
     * ----------------------------------------------------------
     * `ORDER BY id DESC` — yang tampak paling wajar, karena id memang urutan
     * penyisipan — MELUMPUHKAN setiap indeks pada tabel ini. Semua indeks di
     * `audit_logs` berujung pada `created_at` (`idx_audit_action`,
     * `idx_audit_actor`, `idx_audit_entity`) atau terdiri darinya
     * (`idx_audit_created`); mengurutkan menurut `id` memaksa MySQL
     * memindai kunci primer dari ujung dan MENYARING, bukan mencari.
     * Terukur pada 1,5 juta baris:
     *
     *   saringan rentang tanggal, `ORDER BY id DESC`            241,4 ms
     *   saringan rentang tanggal, `ORDER BY created_at, id`       0,6 ms
     *   saringan aksi + rentang,  `ORDER BY id DESC`            211,1 ms
     *   saringan aksi + rentang,  `ORDER BY created_at, id`       0,6 ms
     *
     * Empat ratus kali, dan sebabnya tidak terlihat sama sekali dari kodenya.
     * `id` tetap ikut sebagai pemutus seri: `created_at` hanya berpresisi
     * milidetik, dan dua baris dalam satu milidetik — yang terjadi pada impor
     * massal — akan membuat kursor melompati baris atau mengulanginya.
     *
     * MENGAPA cursorPaginate, BUKAN paginate (docs/23 §7 butir 4)
     * -----------------------------------------------------------
     * `paginate()` memanggil `COUNT(*)` atas seluruh tabel pada SETIAP
     * pembukaan halaman: ± 95 ms yang tidak dapat dipercepat, karena ia
     * memang harus menyentuh setiap baris. Dan ia dibayar untuk sesuatu yang
     * tidak dibutuhkan siapa pun di sini — nomor halaman dan jumlah total
     * baris. Log aktivitas dibaca dari atas ke bawah.
     *
     * @param  array<string, mixed>  $filters
     * @return CursorPaginator<int, AuditLog>
     */
    public function search(array $filters, int $perPage = self::PER_PAGE): CursorPaginator
    {
        $query = AuditLog::query();

        $this->applyFilters($query, $filters);

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage);
    }

    /**
     * Mengalirkan baris tersaring untuk ekspor — tanpa menahannya di memori.
     *
     * `lazyById('id')` dan BUKAN `lazy()`: yang kedua membagi kuerinya dengan
     * LIMIT/OFFSET, sehingga potongan terakhir melewati seluruh baris
     * sebelumnya dan biayanya tumbuh kuadratik. Itu tepat cacat yang ditutup
     * KEP-020 pada `AuditLogger::verifyChain()`, dan tidak ada alasan
     * mengulanginya di sini.
     *
     * Urutannya karena itu MENURUT `id` menaik, bukan `created_at` menurun
     * seperti pada layar: `lazyById()` menuntut kunci yang unik dan menaik,
     * dan ekspor dibaca dengan alat lain — Excel menyortir sendiri.
     *
     * @param  array<string, mixed>  $filters
     * @param  callable(AuditLog): void  $callback
     */
    public function eachForExport(array $filters, callable $callback): void
    {
        $query = AuditLog::query();

        $this->applyFilters($query, $filters);

        foreach ($query->lazyById(1_000) as $entry) {
            $callback($entry);
        }
    }

    /**
     * Jumlah baris tersaring — HANYA untuk dicatat pada baris audit ekspor.
     *
     * Sengaja TIDAK dipakai halaman: lihat keterangan `search()` tentang
     * `COUNT(*)`. Pada ekspor ia dibayar sekali, dan ia harus dibayar, karena
     * baris audit yang mencatat ekspor tanpa menyebut jumlah barisnya tidak
     * dapat menjawab pertanyaan "seberapa banyak yang keluar" — satu-satunya
     * pertanyaan yang penting setelah data meninggalkan sistem.
     *
     * @param  array<string, mixed>  $filters
     */
    public function countForExport(array $filters): int
    {
        $query = AuditLog::query();

        $this->applyFilters($query, $filters);

        return $query->count();
    }

    /**
     * Daftar aksi yang BENAR-BENAR ada di tabel, untuk saringan di layar.
     *
     * Bukan daftar tetap yang ditulis tangan, karena daftar semacam itu akan
     * tertinggal pada aksi berikutnya yang ditambahkan seseorang — dan aksi
     * yang tidak ada di saringan sama saja dengan aksi yang tidak dapat
     * dicari. `SELECT DISTINCT action` memakai loose index scan atas
     * `idx_audit_action`: terukur 4,8 ms untuk 12 aksi pada 1,5 juta baris,
     * jadi ia tidak perlu di-cache.
     *
     * @return list<string>
     */
    public function availableActions(): array
    {
        /** @var list<string> $actions */
        $actions = AuditLog::query()
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->all();

        return $actions;
    }

    /**
     * Daftar jenis entitas yang ada di tabel.
     *
     * `entity_type` adalah kolom pertama `idx_audit_entity`, jadi DISTINCT di
     * sini memakai loose index scan dengan cara yang sama seperti `action`.
     *
     * @return list<string>
     */
    public function availableEntityTypes(): array
    {
        /** @var list<string> $types */
        $types = AuditLog::query()
            ->select('entity_type')
            ->whereNotNull('entity_type')
            ->distinct()
            ->orderBy('entity_type')
            ->pluck('entity_type')
            ->all();

        return $types;
    }

    /**
     * Daftar pelaku untuk saringan — dan satu jebakan yang mahal.
     *
     * `SELECT DISTINCT actor_id, actor_name` adalah bentuk yang paling wajar
     * ditulis, dan ia memindai SELURUH TABEL: terukur **654 ms** pada 1,5
     * juta baris, untuk menghasilkan sembilan baris. Sebabnya
     * `idx_audit_actor` memuat `(actor_id, created_at)` saja — `actor_name`
     * tidak ada di dalamnya, sehingga setiap baris harus dibuka di indeks
     * berkluster.
     *
     * `SELECT DISTINCT actor_id` atas indeks yang sama: **3,0 ms**. Namanya
     * lalu diambil dari tabel `users` — sembilan kunci primer, satu kueri.
     *
     * YANG TERSISA SESUDAHNYA, dan mengapa ia justru yang penting: pelaku
     * yang AKUNNYA SUDAH DIHAPUS tidak ada di `users`. Ia tidak boleh hilang
     * dari saringan — pemeriksa yang mencari jejak pejabat yang sudah
     * diberhentikan justru itu yang dicarinya. Untuk id semacam itu namanya
     * dibaca dari audit log sendiri, satu pencarian berindeks per id, karena
     * `actor_name` di sana memang disimpan denormal tepat untuk alasan ini.
     *
     * Bentuk kembaliannya `{value, label}` dan BUKAN `{id, name}`, karena ia
     * langsung menjadi `options` sebuah `<SelectField>`. Dengan kunci yang
     * lain, komponen itu merender daftar pilihan KOSONG — tanpa satu pun
     * galat, di peramban maupun di vue-tsc, karena `Option[]` yang
     * dideklarasikannya hanya diperiksa pada pemanggilan dari TypeScript,
     * bukan pada muatan yang datang dari Laravel. Ditemukan tepat begitu:
     * halamannya tampak benar, saringan pelakunya kosong.
     *
     * @return list<array{value: string, label: string}>
     */
    public function availableActors(): array
    {
        /** @var list<string> $ids */
        $ids = AuditLog::query()
            ->select('actor_id')
            ->whereNotNull('actor_id')
            ->distinct()
            ->pluck('actor_id')
            ->all();

        if ($ids === []) {
            return [];
        }

        /** @var array<string, string> $names */
        $names = User::query()
            ->whereIn('id', $ids)
            ->pluck('name', 'id')
            ->all();

        $actors = [];

        foreach ($ids as $id) {
            if (isset($names[$id])) {
                $actors[] = ['value' => $id, 'label' => $names[$id]];

                continue;
            }

            /*
             * Akun sudah dihapus. Namanya masih ada di audit log, dan
             * ditandai terbuka di layar supaya pemeriksa tahu ia tidak dapat
             * lagi membuka profil orang itu — bukan supaya ia menyangka
             * namanya salah.
             */
            $recorded = AuditLog::query()
                ->where('actor_id', $id)
                ->value('actor_name');

            $actors[] = [
                'value' => $id,
                'label' => is_string($recorded) && $recorded !== ''
                    ? $recorded.' (akun dihapus)'
                    : '(akun dihapus)',
            ];
        }

        usort($actors, fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $actors;
    }

    /**
     * TIDAK ADA SARINGAN TEKS BEBAS, dan itu keputusan yang diukur.
     *
     * `WHERE actor_name LIKE '%…%'` tidak dapat memakai indeks apa pun.
     * Terukur pada 1,5 juta baris: 5,6 ms bila kebetulan ada yang cocok di
     * dekat ujung tabel, dan **839,4 ms** bila tidak ada yang cocok sama
     * sekali — yaitu tepat pada pencarian yang salah ketik, yang paling
     * sering dilakukan orang. Halaman yang menggantung selama hampir satu
     * detik justru ketika penggunanya keliru adalah halaman yang mengajari
     * orang untuk tidak memakainya.
     *
     * Yang menggantikannya: pelaku dipilih dari daftar (`availableActors()`),
     * dan entitas dicari dengan `entity_type` + `entity_id` yang keduanya
     * berada di `idx_audit_entity` — terukur 0,6 ms.
     *
     * @param  Builder<AuditLog>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (is_string($filters['action'] ?? null) && $filters['action'] !== '') {
            $query->where('action', $filters['action']);
        }

        if (is_string($filters['actor_id'] ?? null) && $filters['actor_id'] !== '') {
            $query->where('actor_id', $filters['actor_id']);
        }

        if (is_string($filters['entity_type'] ?? null) && $filters['entity_type'] !== '') {
            $query->where('entity_type', $filters['entity_type']);
        }

        if (is_string($filters['entity_id'] ?? null) && trim((string) $filters['entity_id']) !== '') {
            $query->where('entity_id', trim((string) $filters['entity_id']));
        }

        /*
         * Tanggal diterima sebagai tanggal kalender desa dan dibandingkan
         * sebagai rentang WAKTU, bukan dengan DATE(created_at).
         *
         * `WHERE DATE(created_at) = ?` membungkus kolomnya dalam fungsi, dan
         * itu membuat `idx_audit_created` tidak dapat dipakai sama sekali —
         * kembali ke pemindaian 1,5 juta baris. Batas atasnya karena itu
         * ditulis sebagai "sebelum awal hari berikutnya", bukan "sampai
         * 23:59:59", supaya baris pada milidetik terakhir hari itu tidak
         * terlewat.
         */
        if (is_string($filters['from'] ?? null) && $filters['from'] !== '') {
            $query->where('created_at', '>=', $filters['from'].' 00:00:00.000');
        }

        if (is_string($filters['to'] ?? null) && $filters['to'] !== '') {
            /*
             * Batasnya dihitung di PHP, bukan dengan DATE_ADD di SQL: nilai
             * ini berasal dari parameter kueri, dan nama kolom maupun ungkapan
             * SQL tidak dapat diikat sebagai parameter. Satu `DB::raw` yang
             * memuat masukan pengguna di berkas ini akan menjadi contoh yang
             * disalin orang berikutnya.
             */
            $query->where(
                'created_at',
                '<',
                Carbon::createFromFormat('Y-m-d', $filters['to'])
                    ->startOfDay()
                    ->addDay()
                    ->format('Y-m-d H:i:s.v')
            );
        }
    }
}
