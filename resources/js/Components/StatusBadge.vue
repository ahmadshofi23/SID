<script setup lang="ts">
import { computed } from 'vue'
import { CircleCheck, CircleMinus, CircleAlert, CircleHelp } from '@lucide/vue'
import type { ResidentStatus } from '@/types'

const props = defineProps<{
    status: ResidentStatus | string
    label: string
}>()

/*
 * Status TIDAK PERNAH dibedakan oleh warna saja.
 *
 * Sekitar 1 dari 12 laki-laki mengalami buta warna, dan di antara perangkat
 * desa hal itu jarang disadari. Setiap status karena itu punya ikon DAN teks
 * (WCAG color-not-only).
 */
const style = computed(() => ({
    aktif: { cls: 'bg-success-soft text-success', icon: CircleCheck },
    meninggal: { cls: 'bg-surface-hover text-content-secondary', icon: CircleMinus },
    pindah_keluar: { cls: 'bg-warning-soft text-warning', icon: CircleAlert },
    hilang: { cls: 'bg-danger-soft text-danger', icon: CircleHelp },
    tidak_diketahui: { cls: 'bg-danger-soft text-danger', icon: CircleHelp },
}[props.status] ?? { cls: 'bg-surface-hover text-content-secondary', icon: CircleHelp }))
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-xs font-medium whitespace-nowrap"
        :class="style.cls"
    >
        <component :is="style.icon" class="size-3.5 shrink-0" aria-hidden="true" />
        {{ label }}
    </span>
</template>
