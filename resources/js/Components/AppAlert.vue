<script setup lang="ts">
import { computed } from 'vue'
import { CircleCheck, Info, TriangleAlert, CircleX } from '@lucide/vue'

const props = withDefaults(defineProps<{
    type?: 'info' | 'success' | 'warning' | 'danger'
    title?: string
}>(), { type: 'info' })

/* Ikon + label tersembunyi untuk pembaca layar: makna tidak bergantung warna. */
const style = computed(() => ({
    info: { icon: Info, cls: 'bg-info-soft text-info border-info/25', sr: 'Informasi' },
    success: { icon: CircleCheck, cls: 'bg-success-soft text-success border-success/25', sr: 'Berhasil' },
    warning: { icon: TriangleAlert, cls: 'bg-warning-soft text-warning border-warning/25', sr: 'Perhatian' },
    danger: { icon: CircleX, cls: 'bg-danger-soft text-danger border-danger/25', sr: 'Gagal' },
}[props.type]))
</script>

<template>
    <div
        class="flex gap-3 rounded-lg border p-4 text-sm animate-rise"
        :class="style.cls"
        :role="type === 'danger' ? 'alert' : 'status'"
    >
        <component :is="style.icon" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
        <div class="min-w-0 flex-1">
            <span class="sr-only">{{ style.sr }}:</span>
            <p v-if="title" class="font-semibold">{{ title }}</p>
            <div :class="title ? 'mt-0.5' : ''"><slot /></div>
        </div>
    </div>
</template>
