<script setup lang="ts">
import { computed } from 'vue'
import {
    FileEdit, Send, Undo2, CircleCheck, Stamp, CircleX, CircleMinus, CircleHelp,
} from '@lucide/vue'
import type { LetterRequestStatus } from '@/types'

const props = defineProps<{
    status: LetterRequestStatus | string
    label: string
}>()

/*
 * Status surat dibedakan oleh IKON dan TEKS, bukan warna saja.
 *
 * Alasannya sama dengan StatusBadge penduduk, tetapi di sini akibatnya lebih
 * berat: membedakan "Diverifikasi" dari "Diterbitkan" hanya dari rona hijau
 * yang berdekatan akan membuat petugas mengira surat sudah terbit padahal
 * belum, lalu warga dipulangkan dengan tangan kosong.
 */
const style = computed(() => ({
    draft: { cls: 'bg-surface-hover text-content-secondary', icon: FileEdit },
    diajukan: { cls: 'bg-info-soft text-info', icon: Send },
    perlu_revisi: { cls: 'bg-warning-soft text-warning', icon: Undo2 },
    diverifikasi: { cls: 'bg-accent-soft text-accent', icon: CircleCheck },
    diterbitkan: { cls: 'bg-success-soft text-success', icon: Stamp },
    ditolak: { cls: 'bg-danger-soft text-danger', icon: CircleX },
    dibatalkan: { cls: 'bg-surface-hover text-content-tertiary', icon: CircleMinus },
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
