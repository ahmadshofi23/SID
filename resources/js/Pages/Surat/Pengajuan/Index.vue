<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { Mail, FilterX, TriangleAlert, Plus, Clock } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/AppButton.vue'
import SelectField from '@/Components/SelectField.vue'
import TextField from '@/Components/TextField.vue'
import EmptyState from '@/Components/EmptyState.vue'
import DataPagination from '@/Components/DataPagination.vue'
import LetterStatusBadge from '@/Components/LetterStatusBadge.vue'
import { usePageData } from '@/composables/usePage'
import type { CursorPage, LetterRequestRow } from '@/types'

const props = defineProps<{
    requests: CursorPage<LetterRequestRow>
    filters: Record<string, string | boolean | null>
    statuses: Record<string, string>
    summary: { by_status: Record<string, number>; overdue: number }
    types: { id: string; code: string; name: string }[]
}>()

const { can } = usePageData()

const status = ref(String(props.filters.status ?? 'terbuka'))
const letterTypeId = ref(String(props.filters.letter_type_id ?? ''))
const ticket = ref(String(props.filters.ticket ?? ''))
const overdueOnly = ref(Boolean(props.filters.overdue))

function apply(): void {
    router.get('/surat', {
        status: status.value || undefined,
        letter_type_id: letterTypeId.value || undefined,
        ticket: ticket.value || undefined,
        overdue: overdueOnly.value ? 1 : undefined,
    }, { preserveState: true, preserveScroll: true, replace: true })
}

watch([status, letterTypeId, overdueOnly], apply)

/* Pencarian tiket ditunda agar tidak mengirim permintaan setiap ketikan. */
let ticketTimer: ReturnType<typeof setTimeout> | undefined
watch(ticket, () => {
    clearTimeout(ticketTimer)
    ticketTimer = setTimeout(apply, 400)
})

function reset(): void {
    status.value = 'terbuka'
    letterTypeId.value = ''
    ticket.value = ''
    overdueOnly.value = false
    apply()
}

const statusOptions = computed(() => [
    { value: 'terbuka', label: 'Semua yang menunggu' },
    { value: '', label: 'Semua status' },
    ...Object.entries(props.statuses).map(([value, label]) => ({ value, label })),
])

const typeOptions = computed(() => [
    { value: '', label: 'Semua jenis surat' },
    ...props.types.map((t) => ({ value: t.id, label: `${t.code} — ${t.name}` })),
])

const waiting = computed(() =>
    (props.summary.by_status.diajukan ?? 0)
    + (props.summary.by_status.diverifikasi ?? 0))

</script>

<template>
    <Head title="Antrean Surat" />

    <AppLayout>
        <header class="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">Antrean Surat</h1>
                <p class="mt-1 text-sm text-content-secondary">
                    Pengajuan yang paling lama menunggu berada di atas.
                </p>
            </div>

            <AppButton v-if="can('surat.ajukan')" href="/surat/ajukan" variant="primary">
                <Plus class="size-4" aria-hidden="true" />
                Ajukan Surat
            </AppButton>
        </header>

        <!-- Ringkasan: dua angka yang menentukan tindakan hari ini -->
        <section class="mb-5 grid gap-3 sm:grid-cols-3" aria-label="Ringkasan antrean">
            <div class="card p-4">
                <p class="text-sm text-content-tertiary">Menunggu diproses</p>
                <p class="mt-1 text-2xl font-semibold tabular">{{ waiting }}</p>
            </div>

            <button
                type="button"
                class="card cursor-pointer p-4 text-left transition-colors hover:bg-surface-hover"
                :class="summary.overdue > 0 ? 'ring-1 ring-danger' : ''"
                @click="overdueOnly = true; status = 'terbuka'"
            >
                <p class="flex items-center gap-1.5 text-sm text-content-tertiary">
                    <TriangleAlert
                        v-if="summary.overdue > 0"
                        class="size-4 text-danger"
                        aria-hidden="true"
                    />
                    Melewati batas waktu
                </p>
                <p
                    class="mt-1 text-2xl font-semibold tabular"
                    :class="summary.overdue > 0 ? 'text-danger' : ''"
                >
                    {{ summary.overdue }}
                </p>
            </button>

            <div class="card p-4">
                <p class="text-sm text-content-tertiary">Perlu revisi warga</p>
                <p class="mt-1 text-2xl font-semibold tabular">
                    {{ summary.by_status.perlu_revisi ?? 0 }}
                </p>
            </div>
        </section>

        <section class="card mb-5 p-4" aria-label="Saringan">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <TextField
                    v-model="ticket"
                    label="Nomor tiket"
                    placeholder="T261009-AB3K7"
                    mono
                />
                <SelectField v-model="status" label="Status" :options="statusOptions" />
                <SelectField v-model="letterTypeId" label="Jenis surat" :options="typeOptions" />

                <div class="flex items-end gap-3">
                    <label class="flex h-11 items-center gap-2 text-sm">
                        <input
                            v-model="overdueOnly"
                            type="checkbox"
                            class="size-4 rounded border-line-control"
                        >
                        Hanya yang terlambat
                    </label>
                    <AppButton size="sm" @click="reset">
                        <FilterX class="size-4" aria-hidden="true" />
                        Reset
                    </AppButton>
                </div>
            </div>
        </section>

        <EmptyState
            v-if="requests.data.length === 0"
            :icon="Mail"
            title="Tidak ada pengajuan"
            description="Belum ada pengajuan surat yang sesuai saringan ini."
        />

        <div v-else class="card overflow-hidden">
            <!-- Tabel di layar lebar -->
            <table class="hidden w-full text-sm md:table">
                <thead class="border-b border-line bg-surface-hover/50 text-left">
                    <tr>
                        <th scope="col" class="px-4 py-2.5 font-medium">Tiket</th>
                        <th scope="col" class="px-4 py-2.5 font-medium">Pemohon</th>
                        <th scope="col" class="px-4 py-2.5 font-medium">Jenis</th>
                        <th scope="col" class="px-4 py-2.5 font-medium">Status</th>
                        <th scope="col" class="px-4 py-2.5 font-medium">Batas waktu</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in requests.data"
                        :key="row.id"
                        class="border-b border-line last:border-0 transition-colors hover:bg-surface-hover/60"
                    >
                        <td class="px-4 py-3">
                            <Link
                                :href="`/surat/${row.id}`"
                                class="font-medium tabular text-accent hover:underline"
                            >
                                {{ row.ticket_number }}
                            </Link>
                            <p v-if="row.channel === 'walk_in'" class="text-xs text-content-tertiary">
                                Loket
                            </p>
                        </td>
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ row.resident.full_name }}</p>
                            <p class="text-xs tabular text-content-tertiary">
                                {{ row.resident.nik_masked }}
                            </p>
                        </td>
                        <td class="px-4 py-3">
                            <p>{{ row.letter_type.code }}</p>
                            <p class="text-xs text-content-tertiary">{{ row.letter_type.name }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <LetterStatusBadge :status="row.status" :label="row.status_label" />
                            <p
                                v-if="row.status === 'diajukan' && row.step_total > 1"
                                class="mt-1 text-xs text-content-tertiary"
                            >
                                Tahap {{ row.current_step + 1 }} dari {{ row.step_total }}
                            </p>
                        </td>
                        <td
                            class="px-4 py-3 whitespace-nowrap"
                            :class="row.is_overdue ? 'font-medium text-danger' : 'text-content-secondary'"
                        >
                            <span class="inline-flex items-center gap-1.5">
                                <Clock class="size-3.5" aria-hidden="true" />
                                {{ row.due_label }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Kartu di ponsel: petugas desa sering memakai ponsel -->
            <ul class="divide-y divide-line md:hidden">
                <li v-for="row in requests.data" :key="row.id">
                    <Link :href="`/surat/${row.id}`" class="block p-4 active:bg-surface-hover">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate font-medium">{{ row.resident.full_name }}</p>
                                <p class="text-xs tabular text-content-tertiary">
                                    {{ row.ticket_number }} · {{ row.letter_type.code }}
                                </p>
                            </div>
                            <LetterStatusBadge :status="row.status" :label="row.status_label" />
                        </div>
                        <p
                            class="mt-2 text-xs"
                            :class="row.is_overdue ? 'font-medium text-danger' : 'text-content-tertiary'"
                        >
                            {{ row.due_label }}
                        </p>
                    </Link>
                </li>
            </ul>
        </div>

        <DataPagination
            v-if="requests.data.length > 0"
            :prev-url="requests.prev_url"
            :next-url="requests.next_url"
            :count="requests.count"
            noun="pengajuan"
        />
    </AppLayout>
</template>
