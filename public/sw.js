/*
 * Service worker — FR NFR docs/03 §2.3 ("shell berfungsi saat jaringan buruk"),
 * docs/07 §8 ("service worker men-cache shell + aset; DATA TETAP DARING").
 *
 * ATURAN YANG MENGIKAT SELURUH BERKAS INI: tidak ada satu pun HTML atau JSON
 * yang disimpan ke cache. Hanya aset statis bernama-hash dan satu halaman
 * luring yang tidak memuat data siapa pun.
 *
 * MENGAPA SEKETAT ITU. Ponsel di desa sering dipakai bergantian — satu
 * perangkat untuk satu keluarga, atau ponsel kantor desa yang dipegang
 * beberapa petugas. Satu halaman daftar penduduk yang tersimpan di cache
 * peramban akan tetap dapat dibuka setelah penggunanya keluar, tanpa melewati
 * satu pun pemeriksaan izin di server. Itu kebocoran yang tidak terlihat di
 * log mana pun dan tidak dapat dicabut dari jauh.
 *
 * Akibatnya diterima dengan sadar: aplikasi ini DAPAT DIPASANG dan membuka
 * lebih cepat, tetapi TIDAK dapat dipakai bekerja saat benar-benar tidak ada
 * jaringan. Yang ditawarkan saat luring hanyalah halaman yang menjelaskan
 * keadaannya — bukan data basi yang disangka pengguna masih berlaku.
 *
 * Ditulis sebagai JavaScript biasa, bukan hasil pembangunan Workbox: berkas
 * ini harus dapat dibaca dan diubah perangkat desa setelah serah terima
 * (docs/01 §6), dan logikanya memang sependek ini.
 */

/*
 * Dinaikkan setiap kali berkas ini ATAU halaman /luring berubah; cache versi
 * lama dibuang saat aktivasi.
 *
 * Halaman luring ikut disebut dengan sengaja: ia hanya diambil sekali, saat
 * pemasangan. Mengubah isinya tanpa menaikkan versi berarti perangkat yang
 * sudah memasang aplikasi akan terus menampilkan halaman luring yang lama
 * selamanya — kesalahan yang tidak terlihat di mesin pengembang, karena di
 * sana cache-nya selalu baru.
 */
const VERSI = 'sid-v1';

const CACHE_ASET = `${VERSI}-aset`;
const CACHE_SHELL = `${VERSI}-shell`;

const HALAMAN_LURING = '/luring';

/** Berkas yang boleh di-cache saat dipakai: nama ber-hash, isinya kekal. */
function asetKekal(url) {
    return url.pathname.startsWith('/build/assets/')
        || url.pathname.startsWith('/ikon/')
        || url.pathname === '/favicon.ico';
}

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_SHELL)
            .then((cache) => cache.add(HALAMAN_LURING))
            /* Gagal mengambil halaman luring tidak boleh membatalkan
               pemasangan: tanpa service worker sama sekali, aplikasi justru
               kehilangan kemampuan dipasang ke layar utama. */
            .catch(() => undefined)
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((kunci) => Promise.all(
                kunci
                    .filter((k) => !k.startsWith(VERSI))
                    .map((k) => caches.delete(k)),
            ))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    // Hanya GET. POST/PUT/DELETE tidak pernah disentuh — mengantre perubahan
    // data secara luring akan menerbitkan surat atas kehendak yang sudah basi.
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    // Permintaan ke domain lain dibiarkan apa adanya.
    if (url.origin !== self.location.origin) {
        return;
    }

    /*
     * Perpindahan halaman: JARINGAN SAJA, dengan halaman luring sebagai
     * jaring terakhir. Jawabannya tidak pernah disimpan — lihat keterangan
     * di atas berkas ini.
     */
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() =>
                caches.match(HALAMAN_LURING).then((luring) =>
                    luring ?? new Response(
                        'Tidak ada koneksi internet.',
                        { status: 503, headers: { 'Content-Type': 'text/plain; charset=UTF-8' } },
                    ),
                ),
            ),
        );

        return;
    }

    if (asetKekal(url)) {
        event.respondWith(
            caches.match(request).then((tersimpan) => {
                if (tersimpan) {
                    return tersimpan;
                }

                return fetch(request).then((jawaban) => {
                    /* Hanya jawaban 200 yang disimpan. Menyimpan 404 atau
                       jawaban sebagian akan membekukan aset rusak sampai
                       versi cache dinaikkan. */
                    if (jawaban.ok && jawaban.status === 200) {
                        const salinan = jawaban.clone();
                        caches.open(CACHE_ASET).then((cache) => cache.put(request, salinan));
                    }

                    return jawaban;
                });
            }),
        );
    }

    /* Sisanya — termasuk setiap permintaan data Inertia — dibiarkan langsung
       ke jaringan tanpa disentuh sama sekali. */
});
