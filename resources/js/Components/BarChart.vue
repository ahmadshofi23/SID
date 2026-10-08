<script setup lang="ts">
import { computed } from 'vue'

interface Item {
    label: string
    total: number | string
}

const props = withDefaults(defineProps<{
    items: Item[]
    caption: string
    emptyText?: string
}>(), { emptyText: 'Belum ada data.' })

/**
 * Nilai "< 5" adalah sel yang disamarkan (ADR-008). Untuk panjang batang ia
 * diperlakukan sebagai 1 — cukup untuk terlihat, tanpa mengungkap angkanya.
 */
function numeric(value: number | string): number {
    return typeof value === 'number' ? value : 1
}

const max = computed(() =>
    Math.max(1, ...props.items.map((i) => numeric(i.total))))
</script>

<template>
    <!--
      Bagan selalu disertai ANGKA di sampingnya, bukan hanya batang.
      Pembaca layar membaca tabel ini apa adanya, dan petugas yang butuh angka
      pasti tidak perlu menebak dari panjang batang (WCAG data-table).
    -->
    <table class="w-full text-sm">
        <caption class="sr-only">{{ caption }}</caption>
        <tbody>
            <tr v-if="items.length === 0">
                <td class="py-4 text-center text-content-tertiary">{{ emptyText }}</td>
            </tr>
            <tr v-for="item in items" :key="item.label">
                <th scope="row" class="w-2/5 py-1.5 pr-3 text-left font-normal align-middle">
                    <span class="line-clamp-2">{{ item.label }}</span>
                </th>
                <td class="py-1.5 align-middle">
                    <div class="flex items-center gap-2">
                        <div
                            class="h-2.5 rounded-full bg-accent transition-[width] duration-500"
                            :style="{ width: `${Math.max(2, (numeric(item.total) / max) * 100)}%` }"
                            aria-hidden="true"
                        />
                        <span class="shrink-0 tabular text-content-secondary">{{ item.total }}</span>
                    </div>
                </td>
            </tr>
        </tbody>
    </table>
</template>
