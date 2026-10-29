<script setup lang="ts">
import { ref, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { FileClock, FilePlus2 } from '@lucide/vue'
import WargaLayout from '@/Layouts/WargaLayout.vue'
import AppButton from '@/Components/AppButton.vue'
import SelectField from '@/Components/SelectField.vue'
import EmptyState from '@/Components/EmptyState.vue'
import DataPagination from '@/Components/DataPagination.vue'
import LetterStatusBadge from '@/Components/LetterStatusBadge.vue'
import type { CursorPage, LetterRequestForCitizen } from '@/types'

const props = defineProps<{
    pengajuan: CursorPage<LetterRequestForCitizen>
    filters: { status: string }
}>()

const status = ref(props.filters.status ?? '')

watch(status, () => {
    router.get('/warga/pengajuan', { status: status.value || undefined },
        { preserveState: true, preserveScroll: true, replace: true })
})

const statusOptions: Record<string, string> = {
    terbuka: 'Sedang diproses',
    selesai: 'Selesai',
    ditolak: 'Ditolak',
    dibatalkan: 'Dibatalkan',
}
</script>

<template>
    <Head title="Pengajuan Saya" />

    <WargaLayout>
        <header class="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">Pengajuan Saya</h1>
                <p class="mt-1 text-sm text-content-secondary">
                    Termasuk pengajuan atas nama anggota Kartu Keluarga yang sama.
                </p>
            </div>
            <AppButton href="/warga/ajukan" variant="primary">
                <FilePlus2 class="size-4" aria-hidden="true" />
                Ajukan
            </AppButton>
        </header>

        <div class="card mb-5 p-4 sm:max-w-xs">
            <SelectField v-model="status" label="Tampilkan" placeholder="— Semua —"
                         :options="statusOptions" />
        </div>

        <EmptyState
            v-if="pengajuan.data.length === 0"
            :icon="FileClock"
            title="Belum ada pengajuan"
            description="Surat yang Anda ajukan dari sini maupun di kantor desa akan tampil di halaman ini."
        >
            <AppButton href="/warga/ajukan" variant="primary">
                <FilePlus2 class="size-4" aria-hidden="true" />
                Ajukan Surat
            </AppButton>
        </EmptyState>

        <ul v-else class="space-y-3">
            <li v-for="p in pengajuan.data" :key="p.id">
                <Link :href="`/warga/pengajuan/${p.id}`"
                      class="card block p-4 transition-colors hover:bg-surface-hover">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-medium">{{ p.letter_type }}</p>
                            <p class="tabular mt-0.5 text-xs text-content-tertiary">
                                {{ p.ticket_number }} · a.n. {{ p.resident_name }}
                            </p>
                        </div>
                        <LetterStatusBadge :status="p.status" :label="p.status_label" />
                    </div>

                    <p class="mt-2 text-xs text-content-secondary">
                        <span v-if="p.submitted_at">Diajukan {{ p.submitted_at }}</span>
                        <span v-if="p.letter_number" class="tabular">
                            · Nomor surat {{ p.letter_number }}
                        </span>
                    </p>

                    <p v-if="p.rejection_reason" class="mt-2 text-xs text-danger">
                        Alasan penolakan: {{ p.rejection_reason }}
                    </p>
                </Link>
            </li>
        </ul>

        <DataPagination
            v-if="pengajuan.data.length > 0"
            :prev-url="pengajuan.prev_url" :next-url="pengajuan.next_url"
            :count="pengajuan.count" noun="pengajuan"
        />
    </WargaLayout>
</template>
