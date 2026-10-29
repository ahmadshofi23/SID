<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { Bell } from '@lucide/vue'
import { usePageData } from '@/composables/usePage'

/*
 * Lonceng notifikasi — dipakai panel petugas maupun portal warga.
 *
 * Jumlahnya datang dari prop bersama, bukan dari kueri di komponen: ia perlu
 * benar di SETIAP halaman, dan komponen yang memuat sendiri akan menampilkan
 * angka basi pada halaman yang dibuka lewat navigasi Inertia.
 */
const { unreadNotifications } = usePageData()
</script>

<template>
    <Link
        href="/notifikasi"
        class="touch-target relative flex items-center justify-center rounded-lg px-2
               text-content-secondary transition-colors hover:bg-surface-hover"
        :aria-label="unreadNotifications > 0
            ? `Notifikasi, ${unreadNotifications} belum dibaca`
            : 'Notifikasi'"
    >
        <Bell class="size-5" aria-hidden="true" />

        <!--
          Penanda jumlah, bukan titik polos: "3 belum dibaca" memberi alasan
          untuk membuka, sementara titik tanpa angka sama saja bagi satu
          maupun dua puluh notifikasi.
        -->
        <span
            v-if="unreadNotifications > 0"
            class="tabular absolute -right-0.5 -top-0.5 flex min-w-4.5 items-center justify-center
                   rounded-full bg-danger px-1 text-[0.625rem] font-semibold leading-4 text-white"
            aria-hidden="true"
        >
            {{ unreadNotifications > 9 ? '9+' : unreadNotifications }}
        </span>
    </Link>
</template>
