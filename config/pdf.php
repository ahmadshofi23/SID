<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Perenderan PDF — KEP-013 §3, ADR-003
|--------------------------------------------------------------------------
| Surat dirender dari HTML oleh Chromium. Ada dua cara menjalankan Chromium,
| dan keduanya dipakai sungguhan:
|
|   gotenberg : produksi. Chromium berjalan di kontainer terpisah, jadi
|               aplikasi tidak perlu menjalankan peramban di dalam dirinya.
|   chrome    : pengembangan di macOS. Mesin pengembang tidak selalu punya
|               runtime kontainer, dan menunggu Docker terpasang akan
|               menghentikan pekerjaan yang tidak ada hubungannya dengan
|               kontainer (KEP-013 §3).
|
| Keduanya WAJIB menghasilkan tata letak yang sama untuk surat yang sama
| (KEP-013 §4) — karena itu ukuran kertas ditentukan oleh CSS `@page` di
| template, bukan oleh opsi masing-masing driver, dan Gotenberg diminta
| menghormati CSS itu (preferCssPageSize).
*/

return [

    'driver' => env('PDF_DRIVER', 'gotenberg'),

    // Batas waktu satu perenderan, dalam detik. Surat satu halaman jauh di
    // bawah ini; batasnya ada agar Chromium yang menggantung tidak menahan
    // worker selamanya.
    'timeout' => (int) env('PDF_TIMEOUT', 60),

    'gotenberg' => [
        'url' => rtrim((string) env('GOTENBERG_URL', 'http://localhost:3000'), '/'),
    ],

    'chrome' => [
        'binary' => env('CHROME_BINARY', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'),
    ],

    /*
    | Disk penyimpanan PDF surat. `local` saat pengembangan, `s3` di produksi
    | (FILESYSTEM_DISK). Disk PRIVAT — PDF surat memuat NIK lengkap dan tidak
    | boleh dapat diambil siapa pun yang menebak URL-nya.
    */
    'disk' => env('PDF_DISK', env('FILESYSTEM_DISK', 'local')),

    /*
    | Ukuran kertas surat desa: F4 (215 × 330 mm), bukan A4.
    |
    | A4 membuat baris tanda tangan jatuh ke halaman kedua pada sebagian
    | surat — cacat yang baru terlihat setelah kertasnya keluar dari printer
    | kantor desa.
    */
    'paper' => [
        'width_mm' => 215,
        'height_mm' => 330,
    ],
];
