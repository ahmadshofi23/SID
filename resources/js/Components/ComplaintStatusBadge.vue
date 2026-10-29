<script setup lang="ts">
import { computed } from 'vue'
import { Inbox, CircleCheck, Hammer, CircleX, CircleHelp } from '@lucide/vue'
import type { ComplaintStatus } from '@/types'

const props = defineProps<{
    status: ComplaintStatus | string
    label: string
}>()

/*
 * Status pengaduan dibedakan oleh IKON dan TEKS, bukan warna saja.
 *
 * Alasannya sama dengan LetterStatusBadge, tetapi di sini ada satu pasangan
 * yang khusus mudah tertukar: "Selesai" dan "Ditolak" keduanya status AKHIR
 * dan sama-sama berhenti bergerak. Warga yang membaca keduanya sebagai
 * "sudah tidak diproses" akan berhenti menunggu kabar — padahal yang satu
 * berarti persoalannya ditangani, yang satu berarti tidak.
 */
const style = computed(() => ({
    baru: { cls: 'bg-info-soft text-info', icon: Inbox },
    diterima: { cls: 'bg-accent-soft text-accent', icon: CircleCheck },
    diproses: { cls: 'bg-warning-soft text-warning', icon: Hammer },
    selesai: { cls: 'bg-success-soft text-success', icon: CircleCheck },
    ditolak: { cls: 'bg-danger-soft text-danger', icon: CircleX },
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
