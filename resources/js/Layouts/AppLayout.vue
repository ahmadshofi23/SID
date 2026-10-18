<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import {
    LayoutDashboard, Users, House, Map, ScrollText, ArrowRightLeft,
    ChartColumn, ShieldAlert, Upload, Mail, FileCheck, Archive, Settings2,
    Menu, X, Sun, Moon, LogOut, ShieldCheck, Landmark, Search, Newspaper, Globe, UserCheck,
} from '@lucide/vue'
import { usePageData } from '@/composables/usePage'
import { useTheme } from '@/composables/useTheme'
import FlashMessages from '@/Components/FlashMessages.vue'
import NotificationBell from '@/Components/NotificationBell.vue'

defineProps<{ title?: string }>()

const { user, village, can } = usePageData()
const { theme, toggle: toggleTheme } = useTheme()

const mobileNavOpen = ref(false)

interface NavItem {
    label: string
    route: string
    pattern: string
    icon: typeof Users
    show: boolean
}

interface NavGroup {
    label: string
    items: NavItem[]
}

/*
 * Menu yang tidak diizinkan TIDAK DITAMPILKAN, bukan sekadar dinonaktifkan.
 * Perangkat desa yang melihat menu lalu ditolak akan mengira sistemnya rusak.
 */
const groups = computed<NavGroup[]>(() => [
    {
        label: 'Beranda',
        items: [
            { label: 'Dashboard', route: '/dashboard', pattern: '/dashboard',
              icon: LayoutDashboard, show: true },
        ],
    },
    {
        label: 'Kependudukan',
        items: [
            { label: 'Data Penduduk', route: '/kependudukan/penduduk',
              pattern: '/kependudukan/penduduk', icon: Users, show: can('penduduk.lihat') },
            { label: 'Kartu Keluarga', route: '/kependudukan/keluarga',
              pattern: '/kependudukan/keluarga', icon: House, show: can('keluarga.lihat') },
            { label: 'Mutasi Penduduk', route: '/kependudukan/mutasi',
              pattern: '/kependudukan/mutasi', icon: ArrowRightLeft, show: can('mutasi.lihat') },
            { label: 'Statistik', route: '/kependudukan/statistik',
              pattern: '/kependudukan/statistik', icon: ChartColumn, show: can('penduduk.lihat') },
            { label: 'Wilayah', route: '/kependudukan/wilayah',
              pattern: '/kependudukan/wilayah', icon: Map, show: can('wilayah.lihat') },
        ],
    },
    {
        // "Kelola Data", bukan "Kualitas Data": petugas yang mencari fitur
        // impor tidak akan menebak bahwa ia ada di bawah "kualitas".
        label: 'Kelola Data',
        items: [
            { label: 'Impor dari Excel', route: '/kependudukan/impor',
              pattern: '/kependudukan/impor', icon: Upload, show: can('penduduk.impor') },
            { label: 'Periksa Anomali', route: '/kependudukan/anomali',
              pattern: '/kependudukan/anomali', icon: ShieldAlert, show: can('penduduk.lihat') },
        ],
    },
    {
        /*
         * "Antrean Surat" lebih dulu, bukan "Arsip": layar yang dibuka
         * petugas setiap pagi adalah daftar pengajuan yang menunggu, dan
         * menu pertama di sebuah kelompok adalah yang paling mudah dicari.
         */
        label: 'Persuratan',
        items: [
            { label: 'Antrean Surat', route: '/surat',
              pattern: '/surat', icon: Mail, show: can('surat.lihat') },
            { label: 'Ajukan Surat', route: '/surat/ajukan',
              pattern: '/surat/ajukan', icon: FileCheck, show: can('surat.ajukan') },
            { label: 'Arsip Surat', route: '/surat/arsip',
              pattern: '/surat/arsip', icon: Archive, show: can('surat.lihat') },
            /*
             * Halaman warga, sengaja ikut di menu petugas.
             *
             * Warga menelepon kantor desa dengan menyebut nomor tiket, dan
             * petugas loket perlu melihat persis apa yang dilihat warga di
             * layarnya — termasuk kalimat alasan penolakannya. Membukanya
             * dari antrean berarti petugas harus lebih dulu mencari
             * pengajuannya, padahal yang ada di tangan hanya nomor tiket.
             *
             * Halaman ini di luar AppLayout (publik, tanpa lingkup), jadi
             * petugas keluar dari panel saat membukanya; itu sebabnya ia
             * menyediakan tautan kembali ke dasbor bagi yang sudah masuk.
             */
            { label: 'Lacak Pengajuan', route: '/surat/lacak',
              pattern: '/surat/lacak', icon: Search, show: can('surat.lihat') },
            { label: 'Jenis Surat', route: '/jenis-surat',
              pattern: '/jenis-surat', icon: Settings2, show: can('jenis_surat.lihat') },
        ],
    },
    {
        label: 'Portal Desa',
        items: [
            { label: 'Konten Portal', route: '/portal/konten',
              pattern: '/portal/konten', icon: Newspaper, show: can('portal.lihat') },
            /*
             * Tautan ke portal publik, sengaja ada di menu petugas: yang
             * menulis berita harus dapat melihat hasilnya seperti yang dilihat
             * warga, bukan hanya pratinjau di dalam panel.
             */
            { label: 'Lihat Portal Publik', route: '/',
              pattern: '/portal-publik', icon: Globe, show: can('portal.lihat') },
        ],
    },
    {
        label: 'Pengaturan',
        items: [
            { label: 'Verifikasi Warga', route: '/pengaturan/verifikasi-warga',
              pattern: '/pengaturan/verifikasi-warga', icon: UserCheck,
              show: can('pengguna.verifikasi') },
            { label: 'Log Aktivitas', route: '/pengaturan/log-aktivitas',
              pattern: '/pengaturan/log-aktivitas', icon: ScrollText, show: can('audit.lihat') },
        ],
    },
].filter((group) => group.items.some((item) => item.show)))

const currentPath = computed(() =>
    typeof window === 'undefined' ? '' : window.location.pathname)

/*
 * Pola TERPANJANG yang cocok yang dianggap aktif, bukan setiap pola yang
 * cocok.
 *
 * Tanpa ini, "/surat" ikut menyala saat halaman "/surat/arsip" dibuka,
 * sehingga tiga menu tampak aktif bersamaan dan penanda posisi kehilangan
 * artinya (WCAG nav-state-active).
 */
const activePattern = computed<string | null>(() => {
    const path = currentPath.value

    const matches = groups.value
        .flatMap((group) => group.items)
        .filter((item) => item.show)
        .map((item) => item.pattern)
        .filter((pattern) => path === pattern || path.startsWith(`${pattern}/`))

    return matches.sort((a, b) => b.length - a.length)[0] ?? null
})

function isActive(pattern: string): boolean {
    return activePattern.value === pattern
}

function logout(): void {
    router.post('/keluar')
}
</script>

<template>
    <div class="min-h-dvh bg-background text-content">
        <FlashMessages />

        <!-- Lompat ke konten: pengguna papan tombol tidak perlu menelusuri
             seluruh menu di setiap halaman (WCAG skip-links). -->
        <a
            href="#konten-utama"
            class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50
                   focus:rounded-lg focus:bg-accent focus:px-4 focus:py-2 focus:text-content-inverse"
        >
            Lompat ke konten utama
        </a>

        <!-- ================= Bilah atas ================= -->
        <header class="sticky top-0 z-30 border-b border-line bg-surface/85 backdrop-blur-md">
            <div class="flex h-14 items-center gap-3 px-4">
                <button
                    type="button"
                    class="flex size-10 items-center justify-center rounded-lg text-content-secondary
                           transition-colors hover:bg-surface-hover cursor-pointer lg:hidden"
                    :aria-expanded="mobileNavOpen"
                    aria-controls="menu-utama"
                    :aria-label="mobileNavOpen ? 'Tutup menu' : 'Buka menu'"
                    @click="mobileNavOpen = !mobileNavOpen"
                >
                    <X v-if="mobileNavOpen" class="size-5" aria-hidden="true" />
                    <Menu v-else class="size-5" aria-hidden="true" />
                </button>

                <Link href="/dashboard" class="flex min-w-0 items-center gap-2.5">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-accent">
                        <Landmark class="size-4 text-content-inverse" aria-hidden="true" />
                    </span>
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-semibold leading-tight">
                            Desa {{ village.name }}
                        </span>
                        <span class="hidden truncate text-xs text-content-tertiary sm:block">
                            Kec. {{ village.district }}, Kab. {{ village.regency }}
                        </span>
                    </span>
                </Link>

                <div class="ml-auto flex items-center gap-1">
                    <NotificationBell />

                    <button
                        type="button"
                        class="flex size-10 items-center justify-center rounded-lg text-content-secondary
                               transition-colors hover:bg-surface-hover cursor-pointer"
                        :aria-label="theme === 'dark' ? 'Beralih ke mode terang' : 'Beralih ke mode gelap'"
                        @click="toggleTheme"
                    >
                        <Sun v-if="theme === 'dark'" class="size-5" aria-hidden="true" />
                        <Moon v-else class="size-5" aria-hidden="true" />
                    </button>

                    <div class="mx-1 hidden text-right sm:block">
                        <p class="text-sm font-medium leading-tight">{{ user?.name }}</p>
                        <p class="text-xs text-content-tertiary">
                            {{ user?.role_labels.join(', ') }}
                        </p>
                    </div>

                    <button
                        type="button"
                        class="flex size-10 items-center justify-center rounded-lg text-content-secondary
                               transition-colors hover:bg-danger-soft hover:text-danger cursor-pointer"
                        aria-label="Keluar dari sistem"
                        @click="logout"
                    >
                        <LogOut class="size-5" aria-hidden="true" />
                    </button>
                </div>
            </div>
        </header>

        <div class="flex">
            <!-- ================= Navigasi samping ================= -->
            <nav
                id="menu-utama"
                class="fixed inset-x-0 top-14 z-20 max-h-[calc(100dvh-3.5rem)] overflow-y-auto
                       border-b border-line bg-surface px-3 py-4
                       lg:sticky lg:top-14 lg:block lg:h-[calc(100dvh-3.5rem)] lg:w-64
                       lg:shrink-0 lg:border-b-0 lg:border-r"
                :class="mobileNavOpen ? 'block' : 'hidden'"
                aria-label="Menu utama"
            >
                <div v-for="group in groups" :key="group.label" class="mb-6 last:mb-0">
                    <p class="mb-1.5 px-3 text-xs font-semibold uppercase tracking-wider
                              text-content-tertiary">
                        {{ group.label }}
                    </p>

                    <template v-for="item in group.items" :key="item.route">
                        <Link
                            v-if="item.show"
                            :href="item.route"
                            class="flex min-h-11 items-center gap-3 rounded-lg px-3 text-sm
                                   transition-colors duration-200"
                            :class="isActive(item.pattern)
                                ? 'bg-accent-soft font-medium text-accent-text'
                                : 'text-content-secondary hover:bg-surface-hover hover:text-content'"
                            :aria-current="isActive(item.pattern) ? 'page' : undefined"
                            @click="mobileNavOpen = false"
                        >
                            <component :is="item.icon" class="size-4.5 shrink-0" aria-hidden="true" />
                            {{ item.label }}
                        </Link>
                    </template>
                </div>

                <!-- Pengingat 2FA diletakkan di dasar menu: terlihat terus-menerus
                     tanpa menghalangi pekerjaan. -->
                <div
                    v-if="user && !user.two_factor_enabled"
                    class="mt-6 rounded-lg border border-warning/30 bg-warning-soft p-3"
                >
                    <div class="flex gap-2">
                        <ShieldCheck class="mt-0.5 size-4 shrink-0 text-warning" aria-hidden="true" />
                        <div class="min-w-0 text-xs text-warning">
                            <p class="font-semibold">Verifikasi dua langkah belum aktif</p>
                            <Link href="/dua-faktor/pasang" class="mt-1 inline-block underline">
                                Aktifkan sekarang
                            </Link>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- ================= Konten ================= -->
            <main
                id="konten-utama"
                tabindex="-1"
                class="min-w-0 flex-1 px-4 py-6 focus:outline-none sm:px-6 lg:px-8"
            >
                <div class="mx-auto max-w-6xl animate-rise">
                    <slot />
                </div>
            </main>
        </div>
    </div>
</template>
