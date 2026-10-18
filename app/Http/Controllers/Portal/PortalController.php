<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Repositories\FamilyRepository;
use App\Services\Population\StatisticsService;
use App\Services\Portal\PortalService;
use Illuminate\Contracts\View\View;

/**
 * Portal publik — FR-PRT-01, FR-PRT-04, FR-PRT-05, BR-20, BR-21, BR-23.
 *
 * TANPA OTENTIKASI dan tanpa izin apa pun: inilah muka desa bagi warga dan
 * bagi instansi lain (docs/03 §2.1). Yang menjaga halaman ini bukan middleware,
 * melainkan PILIHAN SUMBER DATA — lihat App\Services\Portal\PortalService.
 *
 * Dirender BLADE, bukan Inertia (KEP-016): docs/07 §7 mewajibkan portal
 * berfungsi tanpa JavaScript dan §8 membatasi transfer awal 500 KB.
 *
 * Tanpa konstruktor (KEP-009): StatisticsService dan FamilyRepository membawa
 * AccessScope, dan Route menyimpan instance controller selama proses hidup.
 */
final class PortalController extends Controller
{
    public function home(
        PortalService $portal,
        StatisticsService $statistics,
        FamilyRepository $families,
    ): View {
        $layanan = $portal->publicServices();

        return view('portal.beranda', [
            // Enam kartu cukup untuk satu layar ponsel; sisanya di halaman layanan.
            'layanan' => $layanan->take(6),
            'jumlahLayanan' => $layanan->count(),
            'angka' => $portal->villageInNumbers($statistics, $families),
        ]);
    }

    public function profil(
        PortalService $portal,
        StatisticsService $statistics,
        FamilyRepository $families,
    ): View {
        return view('portal.profil', [
            'angka' => $portal->villageInNumbers($statistics, $families),
        ]);
    }

    public function wilayah(PortalService $portal): View
    {
        return view('portal.wilayah', ['dusun' => $portal->regionTree()]);
    }

    public function pemerintahan(PortalService $portal): View
    {
        return view('portal.pemerintahan', ['perangkat' => $portal->organizationChart()]);
    }

    public function layanan(PortalService $portal): View
    {
        return view('portal.layanan', ['layanan' => $portal->publicServices()]);
    }

    public function layananDetail(string $kode, PortalService $portal): View
    {
        $jenis = $portal->findPublicService($kode);

        /*
         * 404, bukan 403, untuk jenis surat yang tidak dibuka ke publik —
         * sama seperti objek di luar lingkup akses (docs/06 §4). 403 akan
         * menyatakan bahwa jenis surat itu memang ada dan sengaja ditutup;
         * bagi pengunjung portal keduanya sama saja artinya: tidak dilayani.
         */
        abort_if($jenis === null, 404);

        return view('portal.layanan-detail', ['jenis' => $jenis]);
    }

    public function statistik(
        PortalService $portal,
        StatisticsService $statistics,
        FamilyRepository $families,
    ): View {
        return view('portal.statistik', [
            /*
             * Penyamaran sel kecil DIPAKU di sini: argumennya tetap `true` dan
             * tidak pernah dibaca dari permintaan (BR-21, ADR-008). Pada layar
             * petugas penandanya memang dapat dimatikan bagi pemegang izin
             * laporan — dan itulah justru sebabnya halaman ini tidak boleh
             * menurunkannya dari query string yang sama.
             */
            'stats' => $statistics->summary(null, true),
            'angka' => $portal->villageInNumbers($statistics, $families),
            'ambangSelKecil' => (int) config('sid.stats_small_cell_threshold', 5),
        ]);
    }

    public function kontak(): View
    {
        return view('portal.kontak');
    }
}
