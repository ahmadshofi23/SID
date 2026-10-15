<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Konfigurasi Sistem Informasi Desa
|--------------------------------------------------------------------------
| Aturan bisnis yang BOLEH berubah per desa disimpan di tabel `settings`
| (dapat diubah dari UI). Berkas ini hanya untuk hal yang bersifat
| teknis/infrastruktur dan tidak diubah oleh pengguna.
| Lihat NFR-MNT-05 pada docs/03-srs.md.
*/

return [

    // Zona waktu untuk PENYAJIAN. Data selalu disimpan UTC (docs/05 §6).
    'timezone' => env('VILLAGE_TIMEZONE', 'Asia/Jakarta'),

    /*
    | Enkripsi data pribadi — ADR-004 (docs/04-arsitektur.md §5).
    | Enkripsi dilakukan di LAPIS APLIKASI, bukan dengan AES_ENCRYPT() MySQL,
    | agar kunci tidak pernah masuk ke slow query log / binlog / PROCESSLIST.
    */
    'pii' => [
        'key' => env('PII_ENCRYPTION_KEY'),
        'pepper' => env('PII_HASH_PEPPER'),
        'cipher' => 'aes-256-gcm',
    ],

    // Kebijakan autentikasi — docs/08-keamanan-privasi.md §3
    'auth' => [
        'password_min_length' => (int) env('AUTH_PASSWORD_MIN_LENGTH', 10),
        'max_login_attempts' => (int) env('AUTH_MAX_LOGIN_ATTEMPTS', 10),
        'throttle_after_attempts' => 5,
        'lockout_minutes' => (int) env('AUTH_LOCKOUT_MINUTES', 30),
        'admin_idle_minutes' => (int) env('AUTH_ADMIN_SESSION_IDLE_MINUTES', 30),
        'admin_absolute_hours' => (int) env('AUTH_ADMIN_SESSION_ABSOLUTE_HOURS', 12),
        'citizen_session_days' => (int) env('AUTH_CITIZEN_SESSION_DAYS', 14),
        'reset_token_minutes' => 60,
        'check_breached' => env('AUTH_CHECK_BREACHED_PASSWORDS', true),
        'two_factor_challenge_ttl' => 300,
        'require_2fa_roles' => array_filter(explode(',', (string) env(
            'AUTH_REQUIRE_2FA_ROLES', 'admin_desa,kepala_desa'
        ))),
    ],

    /*
    | Tabel yang HANYA boleh diakses melalui lapis repository.
    | Ditegakkan oleh tests/Architecture/RepositoryBoundaryTest.php — lihat
    | docs/04-arsitektur.md §4.1. Tanpa Row-Level Security (MySQL, KEP-002),
    | lapis inilah satu-satunya penegak pembatasan wilayah & keluarga.
    */
    'guarded_tables' => [
        'residents',
        'families',
        'letter_requests',
        'letters',
        'complaints',
    ],

    /*
    | Berkas unggahan — NFR-SEC-05, docs/04 §6 "Berkas".
    |
    | Disk PRIVAT: `local` saat pengembangan (storage/app/private, DI LUAR
    | web root), `s3` di produksi. Lampiran syarat berisi fotokopi KTP dan
    | Kartu Keluarga; tidak boleh ada satu pun yang dapat diambil dengan
    | menebak URL-nya. Karena itu pula tidak ada URL pra-tanda-tangan yang
    | beredar: setiap permintaan melewati pemeriksaan lingkup wilayah.
    */
    'uploads' => [
        'disk' => env('UPLOAD_DISK', env('FILESYSTEM_DISK', 'local')),

        // 5 MB per berkas (docs/03 §120 poin 5). Fotokopi KTP hasil pindai
        // ponsel jauh di bawah ini; batasnya ada agar satu unggahan tidak
        // menghabiskan kuota penyimpanan desa.
        'max_bytes' => 5 * 1024 * 1024,

        // Banyaknya berkas per pengajuan. Jenis surat dengan syarat
        // terpanjang (SKKL) meminta lima berkas; kelipatannya memberi ruang
        // untuk halaman kedua fotokopi tanpa membuka pintu penyalahgunaan.
        'max_per_request' => 20,
    ],

    // Penyamaran sel kecil pada statistik publik — ADR-008
    'stats_small_cell_threshold' => 5,
];
