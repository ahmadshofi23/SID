<?php

declare(strict_types=1);

/*
 * Router untuk server bawaan PHP saat uji beban — docs/09-testing-qa.md §4.5.
 *
 * MENGAPA BUKAN `php artisan serve`
 * ---------------------------------
 * Dua alasan, dan keduanya mengubah angka yang terukur:
 *
 * 1. Router bawaan Laravel (Illuminate/Foundation/resources/server.php)
 *    menulis SATU BARIS LOG ke stdout untuk setiap permintaan. Pada 50
 *    pengguna bersamaan itu berarti puluhan ribu penulisan I/O yang
 *    diserialkan — yang terukur sebagian adalah biaya mencatat, bukan biaya
 *    melayani.
 *
 * 2. `artisan serve` tidak melewatkan `PHP_CLI_SERVER_WORKERS` ke proses
 *    anaknya pada versi yang dipakai di sini, sehingga servernya melayani
 *    permintaan SATU DEMI SATU. Uji 50 pengguna bersamaan di atas server
 *    satu pekerja tidak mengukur aplikasinya; ia mengukur panjang antrean.
 *
 * Server bawaan PHP tetap BUKAN php-fpm + nginx. Konsekuensinya dicatat
 * terbuka di docs/23-uji-beban-sprint-7.md §3 agar angkanya tidak dibaca
 * sebagai angka produksi.
 *
 * CARA PAKAI — lihat tests/Load/README.md.
 */

$publicPath = realpath(__DIR__.'/../../public');

if ($publicPath === false) {
    http_response_code(500);
    exit("Direktori public tidak ditemukan.\n");
}

$uri = urldecode((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Berkas statis (aset Vite, ikon, sw.js) dilayani server langsung.
if ($uri !== '/' && is_file($publicPath.$uri)) {
    return false;
}

require_once $publicPath.'/index.php';
