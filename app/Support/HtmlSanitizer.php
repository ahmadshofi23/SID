<?php

declare(strict_types=1);

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Pembersih HTML untuk template surat.
 *
 * MENGAPA INI ADA: template surat adalah HTML yang disunting petugas dari
 * peramban, dan hasilnya ditampilkan dengan `v-html` di halaman cetak. Tanpa
 * pembersihan, seorang Operator yang memegang izin `jenis_surat.kelola` dapat
 * menitipkan <script> di dalam template, yang kemudian DIJALANKAN di peramban
 * Kepala Desa saat ia membuka layar persetujuan — peningkatan hak akses, bukan
 * sekadar tampilan rusak.
 *
 * Pendekatannya DAFTAR IZIN (allowlist), bukan daftar larangan: tag dan
 * atribut yang tidak tercantum dibuang. Daftar larangan selalu ketinggalan
 * dari cara baru menyisipkan skrip (`onpointerrawupdate`, `javascript:` dalam
 * bentuk terenkode, dan sejenisnya).
 *
 * Dipakai di DUA titik — saat template disimpan dan saat surat dirender —
 * karena template yang sudah tersimpan sebelum aturan ini ada juga harus aman.
 *
 * DUA PROFIL, BUKAN SATU DAFTAR GABUNGAN
 * --------------------------------------
 * Profil `surat` (bawaan) untuk template surat; profil `artikel` untuk isi
 * berita, pengumuman, dan agenda di portal, yang memang memerlukan tautan dan
 * gambar. Keduanya TIDAK disatukan: surat resmi tidak pernah memuat tautan,
 * dan mengizinkan <a> di sana hanya memperlebar permukaan serangan pada
 * dokumen yang dibaca Kepala Desa saat menyetujui.
 */
final class HtmlSanitizer
{
    public const PROFIL_SURAT = 'surat';

    public const PROFIL_ARTIKEL = 'artikel';

    /**
     * Tambahan khusus artikel portal: tautan dan gambar.
     *
     * `target` TIDAK diizinkan meski lumrah. target="_blank" tanpa
     * rel="noopener" membuka reverse tabnabbing, dan menambahkan rel otomatis
     * berarti menyunting atribut yang diketik petugas tanpa ia tahu. Tautan
     * yang terbuka di tab yang sama tidak punya masalah itu sama sekali.
     */
    private const ARTICLE_TAGS = ['a', 'img', 'figure', 'figcaption'];

    /** @var array<string, list<string>> */
    private const ARTICLE_ATTRIBUTES = [
        'a' => ['href', 'title'],
        'img' => ['src', 'alt', 'width', 'height', 'loading'],
    ];

    /**
     * Skema URL yang boleh muncul pada href/src.
     *
     * `data:` ditolak walau terlihat tidak berbahaya untuk gambar: ia juga
     * menerima `data:text/html`, dan ukurannya melanggar anggaran 500 KB
     * halaman portal (NFR-PERF-04). URL relatif (diawali `/` atau tanpa
     * skema) tetap diterima — gambar unggahan desa justru berbentuk itu.
     */
    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /** Tag yang masuk akal untuk isi surat resmi. */
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 'span', 'div',
        'table', 'thead', 'tbody', 'tr', 'td', 'th',
        'ul', 'ol', 'li', 'pre', 'blockquote',
        'h1', 'h2', 'h3', 'h4', 'hr', 'sub', 'sup',
    ];

    /**
     * Atribut yang diizinkan per tag.
     *
     * `style` TIDAK diizinkan: ia membuka `expression()` pada peramban lama
     * dan `url(javascript:...)`, dan tata letak surat sudah ditentukan
     * stylesheet halaman cetak. Petugas tidak perlu mengatur gaya per elemen.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_ATTRIBUTES = [
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan', 'scope'],
        'table' => ['border'],
    ];

    public function clean(string $html, string $profile = self::PROFIL_SURAT): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = new DOMDocument;

        /*
         * libxml menulis peringatan untuk HTML tak lengkap langsung ke
         * keluaran PHP. Potongan isi surat MEMANG tidak lengkap (tanpa
         * <html>/<body>), jadi peringatannya diharapkan dan dibungkam —
         * bukan diabaikan secara membabi buta.
         */
        $previous = libxml_use_internal_errors(true);

        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="sid-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('sid-root');

        if (! $root instanceof DOMElement) {
            return '';
        }

        $this->cleanNode($root, $profile);

        $output = '';

        foreach ($root->childNodes as $child) {
            $output .= $document->saveHTML($child);
        }

        return trim($output);
    }

    /** Tag yang dibuang beserta ISINYA, bukan hanya tagnya. */
    private const STRIP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'template'];

    private function cleanNode(DOMNode $node, string $profile): void
    {
        // Disalin lebih dulu: menghapus simpul saat menelusuri childNodes
        // membuat daftarnya bergeser dan sebagian anak terlewat.
        $children = [];

        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            if (in_array($tag, self::STRIP_WITH_CONTENT, true)) {
                $node->removeChild($child);

                continue;
            }

            if (! in_array($tag, $this->allowedTags($profile), true)) {
                /*
                 * Tag tak dikenal dibuang tetapi ISINYA dipertahankan.
                 *
                 * Petugas yang menempel teks dari Word menghasilkan tag
                 * seperti <font> atau <o:p>; membuang isinya berarti
                 * kalimat hilang dari surat tanpa penjelasan.
                 */
                $this->cleanNode($child, $profile);
                $this->unwrap($child);

                continue;
            }

            if ($this->stripAttributes($child, $tag, $profile)) {
                /*
                 * Tautan atau gambar dengan URL berskema terlarang
                 * (javascript:, data:) kehilangan seluruh gunanya; tagnya
                 * dibuka agar teksnya tetap terbaca, bukan dibuang bersama
                 * isinya — kalimat yang hilang tanpa penjelasan lebih
                 * membingungkan petugas daripada tautan yang mati.
                 */
                $this->cleanNode($child, $profile);
                $this->unwrap($child);

                continue;
            }

            $this->cleanNode($child, $profile);
        }
    }

    private function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    /** @return bool true bila elemen ini harus dibuka (URL-nya ditolak). */
    private function stripAttributes(DOMElement $element, string $tag, string $profile): bool
    {
        $allowed = $this->allowedAttributes($profile)[$tag] ?? [];
        $names = [];

        foreach ($element->attributes ?? [] as $attribute) {
            $names[] = $attribute->nodeName;
        }

        foreach ($names as $name) {
            if (! in_array(strtolower($name), $allowed, true)) {
                $element->removeAttribute($name);
            }
        }

        foreach (['href', 'src'] as $urlAttribute) {
            if (! $element->hasAttribute($urlAttribute)) {
                continue;
            }

            if (! $this->isSafeUrl($element->getAttribute($urlAttribute))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apakah URL ini boleh dimuat peramban pembaca?
     *
     * Pemeriksaan dilakukan setelah seluruh spasi putih dibuang: `java\0script:`
     * dan `java\tscript:` masih dijalankan sebagian peramban, dan keduanya
     * lolos perbandingan apa adanya.
     */
    private function isSafeUrl(string $url): bool
    {
        $bersih = strtolower((string) preg_replace('/[\s\x00-\x1F]+/', '', $url));

        if ($bersih === '') {
            return false;
        }

        // Tanpa skema: URL relatif atau jangkar — selalu ke situs desa sendiri.
        if (! preg_match('/^([a-z][a-z0-9+.-]*):/', $bersih, $cocok)) {
            return true;
        }

        return in_array($cocok[1], self::ALLOWED_SCHEMES, true);
    }

    /** @return list<string> */
    private function allowedTags(string $profile): array
    {
        return $profile === self::PROFIL_ARTIKEL
            ? [...self::ALLOWED_TAGS, ...self::ARTICLE_TAGS]
            : self::ALLOWED_TAGS;
    }

    /** @return array<string, list<string>> */
    private function allowedAttributes(string $profile): array
    {
        return $profile === self::PROFIL_ARTIKEL
            ? [...self::ALLOWED_ATTRIBUTES, ...self::ARTICLE_ATTRIBUTES]
            : self::ALLOWED_ATTRIBUTES;
    }

    /**
     * Apakah template memuat sesuatu yang akan dibuang?
     *
     * Dipakai editor template untuk MEMBERI TAHU petugas, bukan hanya
     * membersihkan diam-diam: petugas yang menempel tabel dari Word dan
     * melihat hasilnya berubah tanpa penjelasan akan mengira sistemnya rusak.
     */
    public function wouldChange(string $html, string $profile = self::PROFIL_SURAT): bool
    {
        return $this->normalize($this->clean($html, $profile)) !== $this->normalize($html);
    }

    private function normalize(string $html): string
    {
        return preg_replace('/\s+/', ' ', trim($html)) ?? $html;
    }
}
