<?php

declare(strict_types=1);

namespace App\Services\Upload;

/**
 * Tipe berkas NYATA, dibaca dari isinya — NFR-SEC-05, docs/04 §6 "Berkas".
 *
 * MENGAPA TIDAK CUKUP MEMAKAI EKSTENSI ATAU CONTENT-TYPE
 * -----------------------------------------------------
 * Keduanya sepenuhnya ditentukan pengirim. `payload.html` yang diganti nama
 * menjadi `ktp.pdf`, dikirim dengan header `application/pdf`, lolos
 * pemeriksaan ekstensi maupun MIME tanpa perlawanan — lalu tersimpan di
 * penyimpanan desa sebagai dokumen resmi yang akan dibuka petugas lain.
 *
 * MENGAPA TIDAK MEMAKAI ATURAN `mimes:` LARAVEL SAJA
 * --------------------------------------------------
 * Aturan itu bersandar pada basis data `magic` milik sistem operasi (finfo).
 * Isinya berbeda antara Mac pengembang dan kontainer produksi, sehingga
 * berkas yang diterima saat pengembangan dapat ditolak di produksi — atau
 * sebaliknya, yang jauh lebih buruk. Daftar di bawah ini kecil, tetap, dan
 * sama di mana pun aplikasi berjalan.
 *
 * Yang DITERIMA hanya tiga tipe (docs/03 §120 poin 5): JPG, PNG, PDF.
 */
final readonly class FileSignature
{
    private function __construct(
        public string $extension,
        public string $mime,
        public string $label,
    ) {}

    /** Berapa bita pertama yang perlu dibaca untuk mengenali semua tipe. */
    private const PEEK_BYTES = 16;

    /**
     * Kenali isi berkas. Mengembalikan null bila tipenya di luar daftar.
     *
     * @param  string  $path  letak berkas di sistem berkas setempat
     */
    public static function detect(string $path): ?self
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        $head = (string) fread($handle, self::PEEK_BYTES);
        fclose($handle);

        return self::fromHead($head);
    }

    /** Varian untuk uji dan pemanggil yang sudah memegang isinya. */
    public static function fromHead(string $head): ?self
    {
        /*
         * JPEG: FF D8 FF. Tiga bita, bukan dua — FF D8 saja juga menjadi awal
         * sebagian berkas biner lain.
         */
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return new self('jpg', 'image/jpeg', 'JPG');
        }

        // PNG: delapan bita tanda tangan lengkap (RFC 2083 §3.1). Bagian
        // "\r\n ... \n" di dalamnya memang sengaja ada untuk menangkap
        // berkas yang rusak karena dikirim sebagai teks.
        if (str_starts_with($head, "\x89PNG\x0D\x0A\x1A\x0A")) {
            return new self('png', 'image/png', 'PNG');
        }

        /*
         * PDF: "%PDF-" WAJIB berada di awal berkas.
         *
         * Spesifikasi PDF membolehkan sampah mendahului tanda ini, dan banyak
         * pembaca memaafkannya. Kelonggaran itu tidak diikuti di sini: berkas
         * yang diawali `<html>` lalu menyelipkan "%PDF-" di tengah adalah
         * persis bentuk serangan yang hendak dicegah aturan ini.
         */
        if (str_starts_with($head, '%PDF-')) {
            return new self('pdf', 'application/pdf', 'PDF');
        }

        return null;
    }

    /** Daftar tipe yang diterima, untuk pesan galat dan atribut `accept`. */
    public static function acceptedLabels(): string
    {
        return 'JPG, PNG, atau PDF';
    }

    /** Nilai atribut `accept` pada <input type="file">. */
    public static function acceptAttribute(): string
    {
        return 'image/jpeg,image/png,application/pdf';
    }

    /** Apakah ekstensi yang dikirim peramban sejalan dengan isi berkas. */
    public function matchesClaimedExtension(string $claimed): bool
    {
        $claimed = strtolower($claimed);

        // `jpeg` dan `jpg` adalah ekstensi yang sama; perbedaannya hanya
        // kebiasaan sistem operasi, dan menolak salah satunya akan tampak
        // seperti kerusakan bagi petugas yang mengunggah foto dari ponsel.
        if ($this->extension === 'jpg') {
            return in_array($claimed, ['jpg', 'jpeg'], true);
        }

        return $claimed === $this->extension;
    }
}
