<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Inertia — letak berkas komponen halaman
|--------------------------------------------------------------------------
|
| Berkas ini ada untuk SATU hal: memberi tahu Inertia bahwa halaman proyek
| ini berada di `resources/js/Pages` dengan P besar.
|
| MENGAPA ITU PERLU DIKATAKAN, dan mengapa ketiadaannya tidak terlihat
| -------------------------------------------------------------------
| Bawaan inertiajs/inertia-laravel v3.5 adalah `resource_path('js/pages')`
| — huruf kecil. Halaman proyek ini ada di `js/Pages`, mengikuti
| `resources/js/app.ts` yang memuatnya dengan glob berakar `./Pages`.
|
| Di macOS, sistem berkasnya TIDAK membedakan huruf besar-kecil, sehingga
| `js/pages` menemukan `js/Pages` dan semuanya tampak benar. Di Linux —
| yaitu CI dan server desa — jalur itu tidak ada sama sekali.
|
| Yang patah karenanya BUKAN perenderan halaman: `pages.ensure_pages_exist`
| bawaannya `false`, sehingga permintaan sungguhan tidak pernah mencari
| berkasnya. Yang patah adalah PENGUJIAN. `AssertableInertia::component()`
| memakai `inertia.testing.ensure_pages_exist` yang bawaannya `true`,
| sehingga setiap `->component(...)` di dalam uji gagal di Linux dengan
| "Inertia page component file [...] does not exist" — sementara seluruhnya
| lulus di mesin pengembang.
|
| Itu kelas kegagalan yang paling mahal: uji yang hijau di tempat ia ditulis
| dan merah hanya di tempat ia dipercaya. Dijaga
| `tests/Architecture/InertiaPagePathTest.php`, yang memeriksa keberadaan
| direktorinya dengan PEMBANDINGAN HURUF PERSIS — sehingga ia gagal di macOS
| juga, bukan hanya di Linux.
|
| Seluruh kunci `pages` ditulis ulang di sini, bukan hanya `paths`:
| `mergeConfigFrom()` menggabungkan pada tingkat teratas saja, sehingga
| menuliskan `paths` sendirian akan MENGHAPUS `extensions` dan
| `ensure_pages_exist` bawaan paket.
|
*/

return [

    'pages' => [

        'ensure_pages_exist' => false,

        'paths' => [

            resource_path('js/Pages'),

        ],

        'extensions' => [

            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',

        ],

    ],

];
