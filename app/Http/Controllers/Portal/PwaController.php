<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\VillageProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Manifest aplikasi & halaman luring — docs/03 §2.3, docs/07 §8.
 *
 * MANIFEST DIBANGKITKAN, bukan berkas statis di public/, dengan alasan yang
 * sama seperti sitemap.xml: nama desa ada di basis data dan diisi saat
 * pemasangan. Berkas statis akan menampilkan "Desa Contoh" di layar utama
 * ponsel warga sampai seseorang ingat menyuntingnya — dan nama di layar utama
 * adalah hal yang paling tidak mungkin diperiksa ulang setelah go-live.
 *
 * Tanpa konstruktor (KEP-009).
 */
final class PwaController extends Controller
{
    public function manifest(): JsonResponse
    {
        $village = VillageProfile::current();
        $nama = $village->name !== '' && $village->name !== null ? $village->name : 'Desa';

        return response()->json([
            'id' => '/',
            'name' => 'Desa '.$nama,
            /*
             * `short_name` adalah yang tercetak di bawah ikon pada layar
             * utama, dan ruangnya sekitar 12 karakter. Dipotong di sini
             * supaya yang terlihat warga adalah nama desanya, bukan
             * "Desa Teluk…".
             */
            'short_name' => Str::limit($nama, 12, ''),
            'description' => 'Layanan administrasi dan informasi resmi Desa '.$nama.'.',
            'lang' => 'id',
            'dir' => 'ltr',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'portrait-primary',
            'theme_color' => '#1B5E3F',
            'background_color' => '#F7F8F7',
            'categories' => ['government'],
            'icons' => [
                ['src' => '/ikon/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/ikon/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                /*
                 * Ikon `maskable` terpisah dengan lambang lebih kecil:
                 * peluncur Android memotongnya menjadi lingkaran atau
                 * kotak bersudut, dan ikon biasa akan terpotong pilarnya.
                 */
                ['src' => '/ikon/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            /*
             * Pintasan tekan-lama pada ikon. Keduanya adalah hal yang
             * dicari warga lebih dulu (docs/07 §5.5), dan pelacakan sengaja
             * ikut: ia bekerja tanpa akun (KEP-014 §5), jadi berguna bagi
             * pemasang aplikasi yang belum mendaftar sekalipun.
             */
            'shortcuts' => [
                [
                    'name' => 'Ajukan surat',
                    'url' => '/warga/ajukan',
                    'icons' => [['src' => '/ikon/icon-192.png', 'sizes' => '192x192']],
                ],
                [
                    'name' => 'Lacak pengajuan',
                    'url' => '/surat/lacak',
                    'icons' => [['src' => '/ikon/icon-192.png', 'sizes' => '192x192']],
                ],
            ],
        ], 200, [
            'Content-Type' => 'application/manifest+json; charset=UTF-8',
            // Nama desa jarang berubah; satu hari cukup, dan peramban tetap
            // memeriksa ulang manifest saat aplikasi dipasang.
            'Cache-Control' => 'public, max-age=86400',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Halaman yang ditampilkan service worker saat tidak ada jaringan.
     *
     * Rute biasa, bukan berkas statis, supaya service worker dapat
     * meng-cache-nya lewat URL yang sama dengan yang dilihat pengguna.
     */
    public function offline(): Response
    {
        return response()->view('portal.luring');
    }
}
