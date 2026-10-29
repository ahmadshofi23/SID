<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogPresenter;
use App\Models\AuditLog;
use App\Services\Audit\AuditLogExporter;
use App\Services\Audit\AuditLogReader;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Log Aktivitas — FR-ADM-05, UC-19, BR-41.
 *
 * Halaman ini adalah satu-satunya cara membaca audit log dari dalam aplikasi.
 * Sebelum ia ada, seluruh pekerjaan yang menjaga keutuhan rantai hash
 * (ADR-005, KEP-020) melindungi catatan yang hanya dapat dibaca lewat MySQL —
 * sehingga kendali A09 docs/08 §4 ("audit log lengkap, peringatan atas pola
 * mencurigakan") bersandar pada orang yang bersedia membuka basis data.
 *
 * TIDAK ADA LINGKUP WILAYAH DI SINI, dan itu disengaja: audit yang disaring
 * menurut wilayah pembacanya tidak bernilai sebagai bukti. Yang membatasi
 * siapa boleh membaca adalah izin `audit.lihat` — Admin Desa, Kepala Desa,
 * dan Pengawas — bukan `AccessScope`.
 *
 * Tanpa konstruktor (KEP-009).
 */
final class AuditLogController extends Controller
{
    public function index(Request $request, AuditLogReader $reader): Response
    {
        $this->authorize('audit.lihat');

        $filters = $this->filters($request);

        $page = $reader->search($filters);

        return Inertia::render('Pengaturan/LogAktivitas', [
            'entri' => [
                'data' => array_map(
                    fn (AuditLog $entry): array => AuditLogPresenter::row($entry),
                    $page->items(),
                ),
                'prev_url' => $page->previousPageUrl(),
                'next_url' => $page->nextPageUrl(),
                /*
                 * `count` adalah jumlah baris DI HALAMAN INI, bukan jumlah
                 * seluruh baris tersaring — dan tidak ada tempat di layar
                 * yang menjanjikan sebaliknya. Jumlah seluruhnya menuntut
                 * COUNT(*) atas 1,5 juta baris pada setiap pembukaan
                 * halaman (docs/23 §7 butir 4); satu angka yang tidak
                 * dibutuhkan siapa pun tidak layak dibayar 95 ms.
                 */
                'count' => count($page->items()),
            ],
            'filters' => $filters,
            'aksi' => $reader->availableActions(),
            'jenis_entitas' => $reader->availableEntityTypes(),
            'pelaku' => $reader->availableActors(),
            /*
             * Tidak ada prop "dapat_ekspor": daftar izin pengguna sudah
             * dikirim ke setiap halaman oleh HandleInertiaRequests, dan
             * halaman memakai `can()` dari usePageData(). Satu prop tambahan
             * di sini berarti dua sumber kebenaran untuk pertanyaan yang sama.
             */
        ]);
    }

    /**
     * Ekspor hasil saringan — FR-ADM-05, izin terpisah.
     *
     * `audit.ekspor` adalah izin tersendiri dan BUKAN kelalaian bahwa hanya
     * Admin Desa memegangnya (RoleSeeder memberi Kepala Desa dan Pengawas
     * `audit.lihat` saja). Membaca di layar meninggalkan pembacanya di dalam
     * sistem; berkas CSV keluar dari sistem dan tidak dapat ditarik kembali.
     * FR-ADM-05 memang membatasi ekspor pada AD/SA.
     */
    public function export(Request $request, AuditLogExporter $exporter): StreamedResponse
    {
        $this->authorize('audit.ekspor');

        return $exporter->stream($this->filters($request));
    }

    /**
     * Saringan yang sama untuk layar dan ekspor — satu sumber, satu bentuk.
     *
     * Kalau keduanya menyusun saringannya masing-masing, tombol "Ekspor" akan
     * suatu saat mengunduh himpunan baris yang BERBEDA dari yang sedang
     * dilihat orang di layar — dan tidak ada yang akan menyadarinya, karena
     * berkas CSV tidak menampilkan saringannya sendiri. (Baris audit yang
     * mencatat ekspornya menampilkannya; berkasnya tidak.)
     *
     * Tanggal divalidasi di sini, bukan di pembacanya: `AuditLogReader`
     * mengubah batas atas menjadi Carbon, dan format yang salah akan menjadi
     * galat 500 alih-alih saringan yang diabaikan.
     *
     * @return array<string, string>
     */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'action' => ['nullable', 'string', 'max:60'],
            'actor_id' => ['nullable', 'string', 'max:36'],
            'entity_type' => ['nullable', 'string', 'max:60'],
            'entity_id' => ['nullable', 'string', 'max:36'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return [
            'action' => (string) ($validated['action'] ?? ''),
            'actor_id' => (string) ($validated['actor_id'] ?? ''),
            'entity_type' => (string) ($validated['entity_type'] ?? ''),
            'entity_id' => (string) ($validated['entity_id'] ?? ''),
            'from' => (string) ($validated['from'] ?? ''),
            'to' => (string) ($validated['to'] ?? ''),
        ];
    }
}
