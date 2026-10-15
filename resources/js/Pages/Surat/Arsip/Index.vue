<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { Archive, FilterX, Printer, CircleX, FileDown } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/AppButton.vue'
import SelectField from '@/Components/SelectField.vue'
import TextField from '@/Components/TextField.vue'
import EmptyState from '@/Components/EmptyState.vue'
import DataPagination from '@/Components/DataPagination.vue'
import type { CursorPage, IssuedLetter } from '@/types'

interface ArchiveRow extends IssuedLetter {
    letter_type: { code: string; name: string }
    ticket_number: string
    resident_name: string
    resident_nik: string
    request_id: string
}

const props = defineProps<{
    letters: CursorPage<ArchiveRow>
    filters: Record<string, string | null>
    types: { id: string; code: string; name: string }[]
}>()

const term = ref(props.filters.term ?? '')
const letterTypeId = ref(props.filters.letter_type_id ?? '')
const status = ref(props.filters.status ?? '')
const from = ref(props.filters.from ?? '')
const to = ref(props.filters.to ?? '')

function apply(): void {
    router.get('/surat/arsip', {
        term: term.value || undefined,
        letter_type_id: letterTypeId.value || undefined,
        status: status.value || undefined,
        from: from.value || undefined,
        to: to.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true })
}

watch([letterTypeId, status, from, to], apply)

let termTimer: ReturnType<typeof setTimeout> | undefined
watch(term, () => {
    clearTimeout(termTimer)
    termTimer = setTimeout(apply, 400)
})

function reset(): void {
    term.value = ''; letterTypeId.value = ''; status.value = ''
    from.value = ''; to.value = ''
    apply()
}

const typeOptions = computed(() => [
    { value: '', label: 'Semua jenis surat' },
    ...props.types.map((t) => ({ value: t.id, label: `${t.code} — ${t.name}` })),
])

const statusOptions = [
    { value: '', label: 'Semua status' },
    { value: 'sah', label: 'Sah' },
    { value: 'dibatalkan', label: 'Dibatalkan' },
]

function issuedDate(iso: string): string {
    return new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' }).format(new Date(iso))
}
</script>

<template>
    <Head title="Arsip Surat" />

    <AppLayout>
        <header class="mb-5">
            <h1 class="text-2xl font-semibold">Arsip Surat</h1>
            <p class="mt-1 text-sm text-content-secondary">
                Surat yang sudah terbit. Isinya tidak dapat diubah — koreksi
                dilakukan dengan pembatalan dan penerbitan surat pengganti.
            </p>
        </header>

        <section class="card mb-5 p-4" aria-label="Saringan">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <TextField v-model="term" label="Nomor surat" placeholder="145/SKTM/…" mono />
                <SelectField v-model="letterTypeId" label="Jenis surat" :options="typeOptions" />
                <SelectField v-model="status" label="Status" :options="statusOptions" />
                <TextField v-model="from" label="Terbit dari" type="date" />
                <TextField v-model="to" label="Terbit sampai" type="date" />
            </div>
            <div v-if="term || letterTypeId || status || from || to" class="mt-3">
                <AppButton size="sm" @click="reset">
                    <FilterX class="size-4" aria-hidden="true" />
                    Hapus saringan
                </AppButton>
            </div>
        </section>

        <EmptyState
            v-if="letters.data.length === 0"
            :icon="Archive"
            title="Belum ada surat terbit"
            description="Surat akan muncul di sini setelah pengajuan disetujui dan nomornya terbit."
        />

        <div v-else class="card overflow-hidden">
            <table class="hidden w-full text-sm md:table">
                <thead class="border-b border-line bg-surface-hover/50 text-left">
                    <tr>
                        <th scope="col" class="px-4 py-2.5 font-medium">Nomor surat</th>
                        <th scope="col" class="px-4 py-2.5 font-medium">Pemohon</th>
                        <th scope="col" class="px-4 py-2.5 font-medium">Jenis</th>
                        <th scope="col" class="px-4 py-2.5 font-medium">Terbit</th>
                        <th scope="col" class="px-4 py-2.5 font-medium">Status</th>
                        <th scope="col" class="px-4 py-2.5 text-right font-medium">
                            <span class="sr-only">Tindakan</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in letters.data"
                        :key="row.id"
                        class="border-b border-line last:border-0 transition-colors hover:bg-surface-hover/60"
                    >
                        <td class="px-4 py-3">
                            <Link
                                :href="`/surat/arsip/${row.id}/cetak`"
                                class="font-medium tabular text-accent hover:underline"
                            >
                                {{ row.letter_number }}
                            </Link>
                            <p class="text-xs tabular text-content-tertiary">
                                {{ row.ticket_number }}
                            </p>
                        </td>
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ row.resident_name }}</p>
                            <p class="text-xs tabular text-content-tertiary">{{ row.resident_nik }}</p>
                        </td>
                        <td class="px-4 py-3">{{ row.letter_type.code }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ issuedDate(row.issued_at) }}</td>
                        <td class="px-4 py-3">
                            <span
                                v-if="row.status === 'dibatalkan'"
                                class="inline-flex items-center gap-1.5 rounded-md bg-danger-soft
                                       px-2 py-0.5 text-xs font-medium text-danger"
                            >
                                <CircleX class="size-3.5" aria-hidden="true" />
                                Dibatalkan
                            </span>
                            <span
                                v-else-if="row.is_expired"
                                class="rounded-md bg-warning-soft px-2 py-0.5 text-xs font-medium text-warning"
                            >
                                Masa berlaku habis
                            </span>
                            <span
                                v-else
                                class="rounded-md bg-success-soft px-2 py-0.5 text-xs font-medium text-success"
                            >
                                Sah
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <AppButton
                                    v-if="row.pdf_ready"
                                    :href="`/surat/arsip/${row.id}/pdf`"
                                    size="sm"
                                    external
                                >
                                    <FileDown class="size-4" aria-hidden="true" />
                                    PDF
                                </AppButton>
                                <AppButton :href="`/surat/arsip/${row.id}/cetak`" size="sm">
                                    <Printer class="size-4" aria-hidden="true" />
                                    Cetak
                                </AppButton>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <ul class="divide-y divide-line md:hidden">
                <li v-for="row in letters.data" :key="row.id">
                    <Link
                        :href="`/surat/arsip/${row.id}/cetak`"
                        class="block p-4 active:bg-surface-hover"
                    >
                        <p class="font-medium tabular">{{ row.letter_number }}</p>
                        <p class="mt-0.5 truncate text-sm">{{ row.resident_name }}</p>
                        <p class="mt-1 text-xs text-content-tertiary">
                            {{ row.letter_type.code }} · {{ issuedDate(row.issued_at) }}
                            <span v-if="row.status === 'dibatalkan'" class="text-danger">
                                · Dibatalkan
                            </span>
                        </p>
                    </Link>
                </li>
            </ul>
        </div>

        <DataPagination
            v-if="letters.data.length > 0"
            :prev-url="letters.prev_url"
            :next-url="letters.next_url"
            :count="letters.count"
            noun="surat"
        />
    </AppLayout>
</template>
