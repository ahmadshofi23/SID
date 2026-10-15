<?php

declare(strict_types=1);

namespace App\Services\Letter;

use App\Models\LetterRequest;
use App\Models\VillageProfile;

/**
 * Menyusun BADAN SURAT yang utuh saat penerbitan — docs/13 §2, ADR-003.
 *
 * MENGAPA DI SERVER, BUKAN DI HALAMAN CETAK:
 *
 * Tata letak surat (docs/13 §2) terdiri dari kop, pembuka, blok identitas,
 * isi pokok, dan penutup. Hanya ISI POKOK yang berasal dari template yang
 * disunting petugas; sisanya dibentuk sistem.
 *
 * Semula bagian sistem itu dirakit oleh halaman cetak Vue dari `snapshot`.
 * Itu melanggar ADR-003 secara halus: menyunting halaman Vue akan mengubah
 * tampilan surat yang SUDAH terbit bertahun-tahun lalu, padahal arsip harus
 * tetap sama seperti saat ditandatangani. Dua cacat nyata lahir dari situ —
 * blok identitas menampilkan NIK tersamar (tidak sah untuk dokumen resmi),
 * dan judul jabatan tercetak "Kepala Desa Desa Sukamaju".
 *
 * Karena itu seluruh badan surat disusun sekali, di sini, lalu disimpan apa
 * adanya di `letters.rendered_html`. Halaman cetak hanya menambahkan kop
 * desa (yang memang boleh mengikuti identitas desa terkini) dan blok tanda
 * tangan dari kolom yang tersimpan di surat itu sendiri.
 */
final class LetterBodyComposer
{
    public function __construct(private readonly LetterTemplateRenderer $renderer) {}

    /**
     * @param  array<string, mixed>  $context  hasil LetterContextBuilder::build()
     */
    public function compose(LetterRequest $request, array $context): string
    {
        $village = VillageProfile::current();
        $type = $request->letterType;

        $heading = $this->signerHeading($context);

        $opening = sprintf(
            '<p>Yang bertanda tangan di bawah ini %s, Kecamatan %s, Kabupaten %s, '
            .'dengan ini menerangkan bahwa:</p>',
            e($heading),
            e((string) $village->district),
            e((string) $village->regency),
        );

        $identity = $this->identityBlock($context);

        // Isi pokok dari template petugas — sudah dibersihkan perender.
        $main = $this->renderer->render($type->template_html, $context);

        $closing = '<p>Demikian surat keterangan ini dibuat dengan sebenarnya '
            .'untuk dipergunakan sebagaimana mestinya.</p>';

        $validity = isset($context['masa_berlaku']) && $type->validity_months !== null
            ? sprintf('<p><em>Surat ini berlaku sampai dengan %s.</em></p>',
                e((string) $context['masa_berlaku']))
            : '';

        return $opening."\n".$identity."\n".$main."\n".$closing."\n".$validity;
    }

    /**
     * "Kepala Desa Sukamaju", bukan "Kepala Desa Desa Sukamaju".
     *
     * @param  array<string, mixed>  $context
     */
    private function signerHeading(array $context): string
    {
        $position = trim((string) ($context['penandatangan_jabatan'] ?? ''));
        $village = trim((string) ($context['desa'] ?? ''));

        if ($position === '') {
            return 'Kepala Desa '.$village;
        }

        /*
         * Jabatan perangkat desa umumnya sudah memuat kata "Desa"
         * ("Kepala Desa", "Sekretaris Desa"). Menyambungnya dengan
         * "Desa <nama>" menghasilkan "Kepala Desa Desa Sukamaju" tercetak
         * pada setiap surat yang keluar.
         */
        return str_ends_with(mb_strtolower($position), 'desa')
            ? $position.' '.$village
            : $position.' Desa '.$village;
    }

    /**
     * Blok identitas pemohon.
     *
     * NIK ditulis LENGKAP: ini dokumen resmi yang akan dicocokkan dengan KTP
     * oleh bank, sekolah, atau kantor kecamatan. NIK tersamar membuat surat
     * tidak dapat dipakai untuk tujuan itu.
     *
     * Pengungkapannya tercatat di audit log oleh LetterRequestService
     * (RULE-07), dan `snapshot` tetap menyimpan versi tersamar.
     *
     * @param  array<string, mixed>  $context
     */
    private function identityBlock(array $context): string
    {
        $rows = [
            'Nama' => $context['nama'] ?? '',
            'NIK' => $context['nik'] ?? '',
            'Tempat/Tanggal Lahir' => $context['tempat_tanggal_lahir'] ?? '',
            'Jenis Kelamin' => $context['jenis_kelamin'] ?? '',
            'Agama' => $context['agama'] ?? '',
            'Status Perkawinan' => $context['status_perkawinan'] ?? '',
            'Pekerjaan' => $context['pekerjaan'] ?? '',
            'Kewarganegaraan' => $context['kewarganegaraan'] ?? '',
            'Alamat' => $context['alamat_lengkap'] ?? '',
        ];

        $cells = '';

        foreach ($rows as $label => $value) {
            $text = trim((string) $value);

            // Baris kosong DIBUANG, bukan dicetak dengan tanda hubung.
            // Data penduduk hasil impor sering tidak lengkap, dan blok
            // identitas berisi deretan "-" membuat surat tampak asal jadi.
            if ($text === '') {
                continue;
            }

            $cells .= sprintf(
                '<tr><td>%s</td><td>:</td><td>%s</td></tr>',
                e($label),
                e($text),
            );
        }

        return '<table>'.$cells.'</table>';
    }
}
