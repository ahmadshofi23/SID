<script setup lang="ts">
import { ref, watch } from 'vue'
import { CircleCheck, TriangleAlert, CircleX, X } from '@lucide/vue'
import { usePageData } from '@/composables/usePage'

const { flash } = usePageData()

interface Toast {
    id: number
    type: 'success' | 'warning' | 'danger'
    message: string
}

const toasts = ref<Toast[]>([])
let nextId = 0

const config = {
    success: { icon: CircleCheck, cls: 'border-success/30 bg-success-soft text-success' },
    warning: { icon: TriangleAlert, cls: 'border-warning/30 bg-warning-soft text-warning' },
    danger: { icon: CircleX, cls: 'border-danger/30 bg-danger-soft text-danger' },
}

function dismiss(id: number): void {
    toasts.value = toasts.value.filter((t) => t.id !== id)
}

function push(type: Toast['type'], message: string): void {
    const id = nextId++
    toasts.value.push({ id, type, message })

    /*
     * Pesan sukses & peringatan menghilang sendiri setelah 6 detik.
     * Pesan GALAT tidak — pengguna harus menutupnya sendiri, karena galat
     * yang hilang sebelum sempat dibaca adalah galat yang terulang.
     */
    if (type !== 'danger') {
        setTimeout(() => dismiss(id), 6000)
    }
}

watch(flash, (value) => {
    if (value.status) push('success', value.status)
    if (value.warning) push('warning', value.warning)
    if (value.error) push('danger', value.error)
}, { immediate: true, deep: true })
</script>

<template>
    <!--
      aria-live="polite" agar pembaca layar mengumumkan pesan tanpa memotong
      pembacaan yang sedang berjalan, dan TANPA memindahkan fokus pengguna
      (WCAG toast-accessibility).
    -->
    <div
        class="pointer-events-none fixed inset-x-0 top-0 z-50 flex flex-col items-center gap-2 p-4
               sm:inset-x-auto sm:right-0 sm:items-end"
        aria-live="polite"
        aria-atomic="false"
    >
        <TransitionGroup
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0 -translate-y-2"
            leave-active-class="transition duration-150 ease-in"
            leave-to-class="opacity-0 scale-95"
        >
            <div
                v-for="toast in toasts"
                :key="toast.id"
                class="pointer-events-auto flex w-full max-w-md items-start gap-3 rounded-lg border
                       p-4 text-sm shadow-[var(--shadow-overlay)] backdrop-blur-sm"
                :class="config[toast.type].cls"
                :role="toast.type === 'danger' ? 'alert' : 'status'"
            >
                <component :is="config[toast.type].icon" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                <p class="min-w-0 flex-1">{{ toast.message }}</p>
                <button
                    type="button"
                    class="shrink-0 rounded p-0.5 opacity-60 transition-opacity hover:opacity-100 cursor-pointer"
                    aria-label="Tutup pesan"
                    @click="dismiss(toast.id)"
                >
                    <X class="size-4" aria-hidden="true" />
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>
