import { ref, readonly } from 'vue'

type Theme = 'light' | 'dark'

const current = ref<Theme>(
    document.documentElement.classList.contains('dark') ? 'dark' : 'light',
)

/**
 * Pengaturan mode terang/gelap.
 *
 * Pilihan disimpan per peramban (localStorage), bukan di basis data: ini
 * preferensi perangkat, bukan data desa. Petugas yang memakai komputer kantor
 * di siang hari dan ponsel di malam hari wajar memilih berbeda.
 */
export function useTheme() {
    function set(theme: Theme): void {
        current.value = theme
        document.documentElement.classList.toggle('dark', theme === 'dark')

        try {
            localStorage.setItem('sid-theme', theme)
        } catch {
            // Mode penyamaran atau penyimpanan diblokir — abaikan.
            // Tema tetap berlaku untuk sesi ini.
        }
    }

    function toggle(): void {
        set(current.value === 'dark' ? 'light' : 'dark')
    }

    return { theme: readonly(current), set, toggle }
}
