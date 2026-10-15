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
 */
final class HtmlSanitizer
{
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

    public function clean(string $html): string
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

        $this->cleanNode($root);

        $output = '';

        foreach ($root->childNodes as $child) {
            $output .= $document->saveHTML($child);
        }

        return trim($output);
    }

    /** Tag yang dibuang beserta ISINYA, bukan hanya tagnya. */
    private const STRIP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'template'];

    private function cleanNode(DOMNode $node): void
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

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                /*
                 * Tag tak dikenal dibuang tetapi ISINYA dipertahankan.
                 *
                 * Petugas yang menempel teks dari Word menghasilkan tag
                 * seperti <font> atau <o:p>; membuang isinya berarti
                 * kalimat hilang dari surat tanpa penjelasan.
                 */
                $this->cleanNode($child);
                $this->unwrap($child);

                continue;
            }

            $this->stripAttributes($child, $tag);
            $this->cleanNode($child);
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

    private function stripAttributes(DOMElement $element, string $tag): void
    {
        $allowed = self::ALLOWED_ATTRIBUTES[$tag] ?? [];
        $names = [];

        foreach ($element->attributes ?? [] as $attribute) {
            $names[] = $attribute->nodeName;
        }

        foreach ($names as $name) {
            if (! in_array(strtolower($name), $allowed, true)) {
                $element->removeAttribute($name);
            }
        }
    }

    /**
     * Apakah template memuat sesuatu yang akan dibuang?
     *
     * Dipakai editor template untuk MEMBERI TAHU petugas, bukan hanya
     * membersihkan diam-diam: petugas yang menempel tabel dari Word dan
     * melihat hasilnya berubah tanpa penjelasan akan mengira sistemnya rusak.
     */
    public function wouldChange(string $html): bool
    {
        return $this->normalize($this->clean($html)) !== $this->normalize($html);
    }

    private function normalize(string $html): string
    {
        return preg_replace('/\s+/', ' ', trim($html)) ?? $html;
    }
}
