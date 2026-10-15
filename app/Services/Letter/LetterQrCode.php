<?php

declare(strict_types=1);

namespace App\Services\Letter;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * QR verifikasi surat — FR-SRT-14.
 *
 * Dibuat DI DALAM aplikasi, bukan diambil dari layanan pembuat QR pihak
 * ketiga seperti pada halaman cetak Sprint 4. Dua alasan, dan keduanya nyata:
 *
 *   - memakai layanan luar berarti mengirim kode verifikasi SETIAP surat desa
 *     ke server orang lain, sehingga pihak itu dapat memeriksa (dan mendata)
 *     surat-surat yang terbit di desa ini;
 *   - PDF dirender Chromium yang, di produksi, berjalan di kontainer tanpa
 *     akses internet — QR dari URL luar akan menjadi kotak kosong di surat
 *     resmi, dan baru ketahuan setelah dicetak.
 */
final class LetterQrCode
{
    /**
     * SVG siap tempel ke dalam HTML (tanpa prolog XML).
     *
     * Ukuran digambar dalam MILIMETER oleh CSS pemanggil; nilai `$size` di
     * sini hanya menentukan kehalusan modul QR.
     */
    public function svg(string $verificationCode, int $size = 240): string
    {
        $writer = new Writer(new ImageRenderer(
            /*
             * Margin 1 modul, bukan 0: pemindai QR membutuhkan "zona tenang"
             * di sekeliling kode. Tanpanya, QR yang menempel pada garis kop
             * atau teks sering gagal terbaca dari kertas.
             */
            new RendererStyle($size, 1),
            new SvgImageBackEnd,
        ));

        $svg = $writer->writeString($this->verificationUrl($verificationCode));

        /*
         * Prolog XML sah di dalam berkas .svg, tetapi tidak di tengah dokumen
         * HTML — Chromium akan menampilkannya sebagai teks di atas QR.
         */
        return trim(preg_replace('/^<\?xml.*?\?>\s*/s', '', $svg) ?? $svg);
    }

    /** URL yang dipindai pihak penerima surat (bank, sekolah, kecamatan). */
    public function verificationUrl(string $verificationCode): string
    {
        return rtrim((string) config('app.url'), '/').'/surat/verifikasi/'.$verificationCode;
    }
}
