<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { ChevronLeft, ChevronRight } from '@lucide/vue'
import AppButton from './AppButton.vue'

defineProps<{
    prevUrl: string | null
    nextUrl: string | null
    count: number
    noun: string
}>()

/* preserveState agar posisi gulir dan isi saringan tidak hilang saat
   berpindah halaman (WCAG state-preservation). */
function go(url: string | null): void {
    if (url) router.visit(url, { preserveState: true, preserveScroll: true })
}
</script>

<template>
    <nav class="mt-4 flex flex-wrap items-center justify-between gap-3" aria-label="Navigasi halaman">
        <p class="text-sm text-content-tertiary">
            Menampilkan <span class="font-medium text-content-secondary tabular">{{ count }}</span>
            {{ noun }}
        </p>

        <div class="flex gap-2">
            <AppButton v-if="prevUrl" size="sm" @click="go(prevUrl)">
                <ChevronLeft class="size-4" aria-hidden="true" />
                Sebelumnya
            </AppButton>
            <AppButton v-if="nextUrl" size="sm" @click="go(nextUrl)">
                Berikutnya
                <ChevronRight class="size-4" aria-hidden="true" />
            </AppButton>
        </div>
    </nav>
</template>
