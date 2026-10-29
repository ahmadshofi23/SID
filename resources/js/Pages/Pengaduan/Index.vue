<script setup lang="ts">
import { ref, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { Megaphone, TriangleAlert, ShieldOff, MapPin, UserCheck } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import SelectField from '@/Components/SelectField.vue'
import TextField from '@/Components/TextField.vue'
import EmptyState from '@/Components/EmptyState.vue'
import DataPagination from '@/Components/DataPagination.vue'
import ComplaintStatusBadge from '@/Components/ComplaintStatusBadge.vue'
import type { CursorPage, ComplaintRow, Option } from '@/types'

const props = defineProps<{
    pengaduan: CursorPage<ComplaintRow>
    filters: {
        status: string
        category_code: string
        priority: string
        assigned_to: string
        q: string
        overdue: boolean
    }
    statuses: Record<string, string>
    priorities: Record<string, string>
    kategori: Record<string, string>
    summary: { by_status: Record<string, number>; overdue: number }
    petugas: Option[]
}>()

const filters = ref({ ...props.filters })

watch(filters, (value) => {
    router.get('/pengaduan/antrean', {
        /*
         * `status` SELALU dikirim, termasuk saat kosong — berbeda dari
         * saringan lain yang dihilangkan ketika kosong.
         *
         * Kosong di sini BERARTI sesuatu: "semua status". Server membedakan
         * parameter yang tidak ada (bawaan: hanya yang terbuka) dari parameter
         * kosong, jadi menghilangkannya akan membuat pilihan "— Semua —"
         * diam-diam kembali ke daftar terbuka.
         */
        status: value.status,
        category_code: value.category_code || undefined,
        priority: value.priority || undefined,
        assigned_to: value.assigned_to || undefined,
        q: value.q || undefined,
        overdue: value.overdue ? 1 : undefined,
    }, { preserveState: true, preserveScroll: true, replace: true })
}, { deep: true })

/*
 * "Terbuka" mendahului status satu per satu, dan menjadi bawaan di server.
 * Layar yang dibuka petugas setiap pagi adalah pekerjaan yang menunggu;
 * daftar yang memuat seluruh pengaduan sejak dulu hanya membuatnya menggulir.
 */
const statusOptions: Record<string, string> = {
    terbuka: 'Masih terbuka',
    ...props.statuses,
}
</script>

<template>
    <Head title="Antrean Pengaduan" />

    <AppLayout title="Antrean Pengaduan">
        <header class="mb-5">
            <h1 class="text-2xl font-semibold">Antrean Pengaduan</h1>
            <p class="mt-1 text-sm text-content-secondary">
                Diurutkan dari yang paling lama menunggu, prioritas tinggi di depan.
            </p>
        </header>

        <!--
          Jumlah terlambat ditampilkan sebagai PERINGATAN tersendiri, bukan
          satu angka di antara angka lain: pengaduan yang didiamkan adalah
          alasan paling umum warga berhenti melapor sama sekali.
        -->
        <button
            v-if="summary.overdue > 0"
            type="button"
            class="mb-5 flex w-full items-center gap-3 rounded-lg border border-warning/40
                   bg-warning-soft p-4 text-left transition-colors hover:bg-warning-soft/70"
            @click="filters.overdue = !filters.overdue"
        >
            <TriangleAlert class="size-5 shrink-0 text-warning" aria-hidden="true" />
            <span class="min-w-0 text-sm">
                <span class="font-medium text-warning">
                    {{ summary.overdue }} pengaduan melewati batas waktu
                </span>
                <span class="mt-0.5 block text-content-secondary">
                    {{ filters.overdue ? 'Menampilkan hanya yang terlambat — klik untuk menampilkan semua.' : 'Klik untuk menampilkan hanya yang terlambat.' }}
                </span>
            </span>
        </button>

        <div class="card mb-5 grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4">
            <SelectField v-model="filters.status" label="Status" placeholder="— Semua —"
                         :options="statusOptions" />
            <SelectField v-model="filters.category_code" label="Kategori" placeholder="— Semua —"
                         :options="kategori" />
            <SelectField v-model="filters.priority" label="Prioritas" placeholder="— Semua —"
                         :options="priorities" />
            <SelectField v-model="filters.assigned_to" label="Petugas" placeholder="— Semua —"
                         :options="petugas" />
            <TextField v-model="filters.q" label="Cari" placeholder="Nomor tiket atau judul"
                       class="sm:col-span-2" />
        </div>

        <EmptyState
            v-if="pengaduan.data.length === 0"
            :icon="Megaphone"
            title="Tidak ada pengaduan"
            description="Belum ada pengaduan yang cocok dengan saringan di atas."
        />

        <ul v-else class="space-y-3">
            <li v-for="p in pengaduan.data" :key="p.id">
                <Link :href="`/pengaduan/antrean/${p.id}`"
                      class="card block p-4 transition-colors hover:bg-surface-hover">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-medium">{{ p.title }}</p>
                            <p class="tabular mt-0.5 text-xs text-content-tertiary">
                                {{ p.ticket_number }} · {{ p.category }}
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <!--
                              Prioritas tinggi ditandai hanya bila memang tinggi:
                              lencana "Normal" pada setiap baris adalah derau yang
                              membuat lencana "Tinggi" ikut tak terbaca.
                            -->
                            <span
                                v-if="p.priority === 'tinggi'"
                                class="rounded-md bg-danger-soft px-2 py-0.5 text-xs font-medium text-danger"
                            >
                                Prioritas tinggi
                            </span>
                            <ComplaintStatusBadge :status="p.status" :label="p.status_label" />
                        </div>
                    </div>

                    <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-content-secondary">
                        <span class="inline-flex items-center gap-1">
                            <ShieldOff v-if="p.is_anonymous" class="size-3.5" aria-hidden="true" />
                            {{ p.reporter_label }}
                        </span>
                        <span v-if="p.region" class="inline-flex items-center gap-1">
                            <MapPin class="size-3.5" aria-hidden="true" />
                            {{ p.region }}
                        </span>
                        <span v-if="p.assignee" class="inline-flex items-center gap-1">
                            <UserCheck class="size-3.5" aria-hidden="true" />
                            {{ p.assignee }}
                        </span>
                        <span v-if="p.created_at">Masuk {{ p.created_at }}</span>
                    </div>

                    <p
                        v-if="p.due_label"
                        class="mt-2 text-xs"
                        :class="p.is_overdue ? 'font-medium text-warning' : 'text-content-tertiary'"
                    >
                        {{ p.due_label }}
                    </p>
                </Link>
            </li>
        </ul>

        <DataPagination
            v-if="pengaduan.data.length > 0"
            :prev-url="pengaduan.prev_url" :next-url="pengaduan.next_url"
            :count="pengaduan.count" noun="pengaduan"
        />
    </AppLayout>
</template>
