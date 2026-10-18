<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Portal\PortalService;
use Illuminate\Http\Response;

/**
 * sitemap.xml & robots.txt — FR-PRT-08, BR-24.
 *
 * MENGAPA DIBANGKITKAN, BUKAN BERKAS STATIS DI public/
 * ----------------------------------------------------
 * 1. Daftar layanan desa berubah dari panel petugas tanpa rilis baru (jenis
 *    surat diaktifkan atau dinonaktifkan). Berkas statis menuntut seseorang
 *    mengingat untuk memperbaruinya, dan yang terjadi adalah mesin pencari
 *    mengantre halaman yang sudah 404 sementara layanan baru tak terindeks.
 * 2. Baris `Sitemap:` pada robots.txt harus berupa URL ABSOLUT. Berkas statis
 *    tidak tahu nama domain desa, sehingga barisnya hanya benar bila seseorang
 *    menyuntingnya saat pemasangan — langkah yang pasti terlewat.
 *
 * Yang masuk ke peta situs HANYA halaman yang boleh terindeks. Panel
 * administrasi, halaman pelacakan, dan halaman verifikasi surat tidak: dua
 * yang terakhir tidak berarti apa pun tanpa nomor tiket atau kode verifikasi,
 * dan mengindeksnya hanya menyebarkan URL yang akan dicoba-coba.
 */
final class SitemapController extends Controller
{
    /** Halaman portal yang boleh terindeks, selain detail layanan. */
    private const STATIC_PAGES = [
        ['route' => 'portal.beranda', 'priority' => '1.0', 'changefreq' => 'weekly'],
        ['route' => 'portal.layanan', 'priority' => '0.9', 'changefreq' => 'monthly'],
        ['route' => 'portal.layanan.detail', 'priority' => '0.8', 'changefreq' => 'monthly'],
        ['route' => 'portal.profil', 'priority' => '0.7', 'changefreq' => 'yearly'],
        ['route' => 'portal.pemerintahan', 'priority' => '0.7', 'changefreq' => 'yearly'],
        ['route' => 'portal.wilayah', 'priority' => '0.5', 'changefreq' => 'yearly'],
        ['route' => 'portal.statistik', 'priority' => '0.6', 'changefreq' => 'monthly'],
        ['route' => 'portal.kontak', 'priority' => '0.6', 'changefreq' => 'yearly'],
    ];

    public function sitemap(PortalService $portal): Response
    {
        $urls = [];

        foreach (self::STATIC_PAGES as $page) {
            if ($page['route'] === 'portal.layanan.detail') {
                // Satu entri per layanan yang benar-benar dibuka ke publik.
                foreach ($portal->publicServices() as $jenis) {
                    $urls[] = [
                        'loc' => route($page['route'], $jenis->code),
                        'priority' => $page['priority'],
                        'changefreq' => $page['changefreq'],
                    ];
                }

                continue;
            }

            $urls[] = [
                'loc' => route($page['route']),
                'priority' => $page['priority'],
                'changefreq' => $page['changefreq'],
            ];
        }

        return response()
            ->view('portal.sitemap', ['urls' => $urls])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function robots(): Response
    {
        /*
         * Panel administrasi sudah memasang <meta name="robots" content="noindex">
         * lewat resources/views/app.blade.php. Daftar di bawah adalah lapis
         * kedua: perayap tidak perlu MEMUAT halamannya lebih dulu untuk
         * mengetahuinya — dan beberapa perayap tidak membaca meta sama sekali.
         */
        $disallow = [
            '/dashboard', '/kependudukan', '/surat',
            '/masuk', '/dua-faktor', '/lupa-sandi', '/atur-sandi', '/keluar',
        ];

        $lines = ['User-agent: *'];

        foreach ($disallow as $path) {
            $lines[] = 'Disallow: '.$path;
        }

        $lines[] = '';
        $lines[] = 'Sitemap: '.route('portal.sitemap');

        return response(implode("\n", $lines)."\n")
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
