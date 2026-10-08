import { computed } from 'vue'
import { usePage as useInertiaPage } from '@inertiajs/vue3'
import type { PageProps } from '@/types'

/** Akses bertipe ke data bersama yang dikirim HandleInertiaRequests. */
export function usePageData() {
    const page = useInertiaPage<PageProps>()

    const user = computed(() => page.props.auth.user)
    const village = computed(() => page.props.village)
    const flash = computed(() => page.props.flash)

    /**
     * Pemeriksaan izin untuk MENYEMBUNYIKAN menu & tombol.
     *
     * Ini kenyamanan, BUKAN kontrol keamanan. Penegakan sebenarnya ada di
     * middleware `izin:` pada setiap rute dan di lapis repository
     * (docs/04-arsitektur.md §4.1). Menyembunyikan tombol di sini hanya
     * mencegah petugas menemui layar 403 yang membingungkan.
     */
    function can(permission: string): boolean {
        const perms = user.value?.permissions ?? []

        return perms.includes('*') || perms.includes(permission)
    }

    function canAny(...permissions: string[]): boolean {
        return permissions.some(can)
    }

    return { page, user, village, flash, can, canAny }
}
