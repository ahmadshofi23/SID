import '../css/app.css'

import { createApp, h, type DefineComponent } from 'vue'
import { createInertiaApp, router } from '@inertiajs/vue3'
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers'

/**
 * Titik masuk panel administrasi — Vue 3 + Inertia (KEP-008).
 *
 * Portal publik TIDAK memakai berkas ini. Ia tetap dirender server dengan
 * Blade demi SEO dan kecepatan muat pertama (ADR-006) — pengunjungnya warga
 * dengan jaringan seluler, bukan petugas di kantor desa.
 */

const appName = import.meta.env.VITE_APP_NAME || 'Sistem Informasi Desa'

/**
 * Tema gelap/terang diterapkan SEBELUM Vue dipasang, agar tidak terjadi
 * kedipan putih sesaat pada pengguna yang memilih mode gelap.
 */
function applyTheme(): void {
    const stored = localStorage.getItem('sid-theme')
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches
    const dark = stored === 'dark' || (stored === null && prefersDark)

    document.documentElement.classList.toggle('dark', dark)
}

applyTheme()

createInertiaApp({
    title: (title) => (title ? `${title} — ${appName}` : appName),

    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./Pages/**/*.vue'),
        ),

    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el)
    },

    progress: {
        color: '#1B5E3F',
        // 250ms: cukup lama agar navigasi cepat tidak memunculkan kedipan bar,
        // cukup singkat agar pengguna tahu sistem sedang bekerja.
        delay: 250,
    },
})

/**
 * Setelah pindah halaman, fokus dipindahkan ke konten utama.
 *
 * Tanpa ini, pengguna pembaca layar tetap berada di posisi lama dan tidak
 * mengetahui halaman sudah berganti (WCAG focus-on-route-change).
 */
router.on('navigate', () => {
    const main = document.getElementById('konten-utama')

    if (main instanceof HTMLElement) {
        main.focus({ preventScroll: true })
    }
})
