<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\AccessScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pintu ANTREAN PETUGAS — kebalikan dari EnsureVerifiedCitizen.
 *
 * MENGAPA MIDDLEWARE INI ADA — ini kebocoran nyata, ditemukan uji penetrasi
 * Sprint 7, bukan kehati-hatian berlebihan.
 *
 * Matriks RBAC docs/03 §4.9 memberi Warga "C,R*" atas pengaduan dan surat:
 * membuat, dan MEMBACA MILIKNYA SENDIRI. Izin bacanya benar — tetapi ia
 * dilayani oleh portal warga (`/warga/*`), bukan oleh antrean petugas.
 * Karena rute antrean hanya menuntut `pengaduan.lihat` / `surat.lihat`, dan
 * warga memang memegang keduanya, warga dapat membuka layar PETUGAS untuk
 * objek miliknya sendiri.
 *
 * Akibat yang terukur, dan inilah yang membuatnya bukan sekadar kejanggalan
 * tampilan: layar petugas menampilkan SELURUH riwayat, termasuk catatan
 * koordinasi internal yang ditandai `is_visible_to_reporter = false`. Seorang
 * warga yang membuka pengaduannya sendiri di sana membaca kalimat seperti
 * "dugaan awal: kabel dicuri tetangga" — dugaan yang belum terbukti, yang
 * sengaja disembunyikan darinya di halaman pelacakan, dan yang dapat
 * berakhir sebagai pertengkaran antartetangga.
 *
 * MENGAPA LINGKUP, BUKAN NAMA PERAN: yang menentukan bukan "bernama warga"
 * melainkan "lingkup datanya adalah keluarganya sendiri". Pengguna dengan
 * lingkup sesempit itu memang tidak punya urusan di antrean yang disusun
 * untuk melihat pekerjaan SELURUH desa, apa pun nama perannya nanti.
 *
 * MENGAPA 403 DAN BUKAN 404: yang disembunyikan di sini bukan keberadaan
 * sebuah objek melainkan keberadaan sebuah HALAMAN — dan halaman itu memang
 * ada serta diketahui umum. Aturan 404 (docs/06 §4) menjaga agar pengguna
 * tidak dapat menyimpulkan apakah objek tertentu ada; ia tidak berlaku di
 * sini. Pesannya mengarahkan warga ke tempat yang benar (KEP-012).
 */
final class EnsureStaffQueue
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app(AccessScope::class)->isFamilyLimited()) {
            abort(403, 'Halaman ini untuk perangkat desa. Pengajuan dan pengaduan '
                .'Anda sendiri dapat dibuka dari menu portal warga.');
        }

        return $next($request);
    }
}
