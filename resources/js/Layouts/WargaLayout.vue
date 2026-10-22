<script setup lang="ts">
import { computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { Landmark, House, FilePlus2, FileClock, Megaphone, Users, LogOut, Sun, Moon } from '@lucide/vue'
import { usePageData } from '@/composables/usePage'
import { useTheme } from '@/composables/useTheme'
import FlashMessages from '@/Components/FlashMessages.vue'
import NotificationBell from '@/Components/NotificationBell.vue'

/*
 * Kerangka portal warga — docs/07 §2.2.
 *
 * TERPISAH DARI AppLayout dengan sengaja. Menu panel petugas berisi modul
 * kependudukan, antrean surat, dan pengaturan; warga tidak punya urusan
 * dengan satu pun di antaranya, dan menyembunyikannya satu per satu dengan
 * can() akan menyisakan kerangka kosong yang terasa seperti sistem yang
 * menolaknya.
 *
 * MOBILE-FIRST, bukan sekadar responsif: navigasinya bilah bawah di ponsel
 * (jangkauan ibu jari) dan menjadi bilah atas di layar lebar. Perangkat
 * dominan warga adalah ponsel Android kelas bawah (docs/03 §2.2).
 */
defineProps<{ title?: string }>()

const { user, village } = usePageData()
const { theme, toggle: toggleTheme } = useTheme()

const menu = [
    { label: 'Beranda', href: '/warga', icon: House },
    { label: 'Ajukan', href: '/warga/ajukan', icon: FilePlus2 },
    { label: 'Pengajuan', href: '/warga/pengajuan', icon: FileClock },
    { label: 'Pengaduan', href: '/warga/pengaduan', icon: Megaphone },
    { label: 'Keluarga', href: '/warga/keluarga', icon: Users },
]

const currentPath = computed(() =>
    typeof window === 'undefined' ? '' : window.location.pathname)

function aktif(href: string): boolean {
    return href === '/warga'
        ? currentPath.value === '/warga'
        : currentPath.value.startsWith(href)
}

function keluar(): void {
    router.post('/keluar')
}
</script>

<template>
    <div class="flex min-h-dvh flex-col bg-background text-content">
        <FlashMessages />

        <a href="#isi"
           class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50
                  focus:rounded-lg focus:bg-accent focus:px-4 focus:py-2 focus:text-content-inverse">
            Lewati ke isi halaman
        </a>

        <header class="border-b border-line bg-surface">
            <div class="mx-auto flex max-w-3xl items-center gap-3 px-4 py-3">
                <Link href="/warga" class="flex min-w-0 items-center gap-2.5">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-accent">
                        <Landmark class="size-5 text-content-inverse" aria-hidden="true" />
                    </span>
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-semibold leading-tight">
                            Layanan Warga
                        </span>
                        <span class="block truncate text-xs text-content-secondary">
                            Desa {{ village.name }}
                        </span>
                    </span>
                </Link>

                <nav class="ml-auto hidden sm:block" aria-label="Menu warga">
                    <ul class="flex items-center gap-1">
                        <li v-for="item in menu" :key="item.href">
                            <Link
                                :href="item.href"
                                :class="[
                                    'rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                    aktif(item.href)
                                        ? 'bg-accent-soft text-accent-text'
                                        : 'text-content-secondary hover:bg-surface-hover hover:text-content',
                                ]"
                                :aria-current="aktif(item.href) ? 'page' : undefined"
                            >
                                {{ item.label }}
                            </Link>
                        </li>
                    </ul>
                </nav>

                <div class="ml-auto flex items-center gap-1 sm:ml-0">
                    <NotificationBell />

                    <button
                        type="button"
                        class="touch-target flex items-center justify-center rounded-lg px-2
                               text-content-secondary hover:bg-surface-hover"
                        :aria-label="theme === 'dark' ? 'Beralih ke mode terang' : 'Beralih ke mode gelap'"
                        @click="toggleTheme"
                    >
                        <component :is="theme === 'dark' ? Sun : Moon" class="size-5" aria-hidden="true" />
                    </button>
                    <button
                        type="button"
                        class="touch-target flex items-center justify-center rounded-lg px-2
                               text-content-secondary hover:bg-surface-hover"
                        aria-label="Keluar"
                        @click="keluar"
                    >
                        <LogOut class="size-5" aria-hidden="true" />
                    </button>
                </div>
            </div>
        </header>

        <main id="isi" class="mx-auto w-full max-w-3xl flex-1 px-4 py-6 pb-24 sm:pb-6">
            <p v-if="user" class="sr-only">Masuk sebagai {{ user.name }}</p>
            <slot />
        </main>

        <!--
          Bilah bawah khusus ponsel. Posisinya di bawah bukan gaya melainkan
          jangkauan: pada ponsel 6 inci, bilah navigasi di atas menuntut
          pengguna memindahkan genggaman setiap kali berpindah layar.
        -->
        <nav class="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface sm:hidden"
             aria-label="Menu warga">
            <ul class="mx-auto flex max-w-3xl">
                <li v-for="item in menu" :key="item.href" class="flex-1">
                    <Link
                        :href="item.href"
                        :class="[
                            'flex min-h-14 flex-col items-center justify-center gap-0.5 text-xs font-medium',
                            aktif(item.href) ? 'text-accent' : 'text-content-secondary',
                        ]"
                        :aria-current="aktif(item.href) ? 'page' : undefined"
                    >
                        <component :is="item.icon" class="size-5" aria-hidden="true" />
                        {{ item.label }}
                    </Link>
                </li>
            </ul>
        </nav>
    </div>
</template>
