<script setup lang="ts">
import { ref, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { Users, Plus, Search, FilterX } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/AppButton.vue'
import SelectField from '@/Components/SelectField.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import SensitiveValue from '@/Components/SensitiveValue.vue'
import EmptyState from '@/Components/EmptyState.vue'
import DataPagination from '@/Components/DataPagination.vue'
import { usePageData } from '@/composables/usePage'
import type { CursorPage, Resident } from '@/types'

const props = defineProps<{
    residents: CursorPage<Resident>
    q: string
    filters: Record<string, string | null>
    regions: Record<string, string>
    statusCounts: Record<string, number>
}>()

const { can } = usePageData()
const nf = new Intl.NumberFormat('id-ID')

const search = ref(props.q)
const regionId = ref(props.filters.region_id ?? '')
const gender = ref(props.filters.gender ?? '')
const status = ref(props.filters.status ?? '')

let timer: ReturnType<typeof setTimeout> | undefined

function apply(immediate = false): void {
    clearTimeout(timer)

    const run = () => router.get('/kependudukan/penduduk', {
        q: search.value || undefined,
        region_id: regionId.value || undefined,
        gender: gender.value || undefined,
        status: status.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true })

    // Pencarian ditunda 400ms: tanpa ini, setiap ketukan tombol memicu satu
    // permintaan — berat bagi jaringan desa dan membuat hasil berkedip.
    immediate ? run() : (timer = setTimeout(run, 400))
}

watch(search, () => apply())
watch([regionId, gender, status], () => apply(true))

function reset(): void {
    search.value = ''
    regionId.value = ''
    gender.value = ''
    status.value = ''
    apply(true)
}

const hasFilter = () =>
    Boolean(search.value || regionId.value || gender.value || status.value)

const aktif = () => props.statusCounts.aktif ?? 0
const total = () => Object.values(props.statusCounts).reduce((a, b) => a + b, 0)
</script>

<template>
    <Head title="Data Penduduk" />

    <AppLayout>
        <header class="mb-5 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">Data Penduduk</h1>
                <p class="mt-1 text-sm text-content-secondary tabular">
                    {{ nf.format(aktif()) }} penduduk aktif
                    <template v-if="total() > aktif()">
                        · {{ nf.format(total() - aktif()) }} tidak aktif (meninggal/pindah)
                    </template>
                </p>
            </div>

            <AppButton v-if="can('penduduk.tambah')" href="/kependudukan/penduduk/tambah" variant="primary">
                <Plus class="size-4" aria-hidden="true" />
                Tambah Penduduk
            </AppButton>
        </header>

        <!-- Saringan -->
        <section class="card mb-5 p-4" aria-label="Saringan data">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="lg:col-span-2">
                    <label for="cari" class="mb-1.5 block text-sm font-medium">Cari</label>
                    <div class="relative">
                        <Search
                            class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2
                                   text-content-tertiary"
                            aria-hidden="true"
                        />
                        <input
                            id="cari"
                            v-model="search"
                            type="search"
                            placeholder="Nama, NIK, atau nomor KK"
                            class="h-11 w-full rounded-lg border border-line-control bg-surface pl-9 pr-3
                                   text-base transition-colors duration-200
                                   placeholder:text-content-tertiary
                                   hover:border-content-tertiary focus:border-accent focus:outline-none"
                            aria-describedby="cari-hint"
                        >
                    </div>
                    <p id="cari-hint" class="mt-1.5 text-sm text-content-tertiary">
                        NIK &amp; nomor KK dicari persis; nama boleh sebagian.
                    </p>
                </div>

                <SelectField v-model="regionId" label="Wilayah (RT)"
                             :options="regions" placeholder="Semua wilayah" />
                <SelectField v-model="gender" label="Jenis kelamin"
                             :options="{ L: 'Laki-laki', P: 'Perempuan' }" placeholder="Semua" />
                <SelectField v-model="status" label="Status"
                             :options="{ aktif: 'Aktif', meninggal: 'Meninggal', pindah_keluar: 'Pindah keluar' }"
                             placeholder="Semua status" />

                <div v-if="hasFilter()" class="flex items-end">
                    <AppButton variant="ghost" @click="reset">
                        <FilterX class="size-4" aria-hidden="true" />
                        Hapus saringan
                    </AppButton>
                </div>
            </div>
        </section>

        <EmptyState
            v-if="residents.data.length === 0"
            :icon="Users"
            :title="hasFilter() ? 'Tidak ada data yang cocok' : 'Belum ada data penduduk'"
            :description="hasFilter()
                ? 'Coba ubah kata kunci atau hapus sebagian saringan.'
                : 'Mulai dengan menambahkan penduduk pertama, atau impor dari Excel (Sprint 3).'"
        >
            <AppButton v-if="hasFilter()" variant="secondary" @click="reset">Hapus saringan</AppButton>
            <AppButton
                v-else-if="can('penduduk.tambah')"
                href="/kependudukan/penduduk/tambah"
                variant="primary"
            >
                <Plus class="size-4" aria-hidden="true" />
                Tambah Penduduk
            </AppButton>
        </EmptyState>

        <template v-else>
            <!-- Layar lebar: tabel -->
            <div class="card hidden overflow-hidden md:block">
                <table class="w-full text-sm">
                    <caption class="sr-only">Daftar penduduk dalam lingkup akses Anda</caption>
                    <thead>
                        <tr class="border-b border-line bg-surface-sunken text-left">
                            <th scope="col" class="px-4 py-3 font-semibold">Nama</th>
                            <th scope="col" class="px-4 py-3 font-semibold">NIK</th>
                            <th scope="col" class="px-4 py-3 font-semibold">L/P</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Umur</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Wilayah</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="r in residents.data"
                            :key="r.id"
                            class="border-b border-line transition-colors duration-150
                                   last:border-0 hover:bg-surface-hover"
                        >
                            <td class="px-4 py-3">
                                <Link
                                    :href="`/kependudukan/penduduk/${r.id}`"
                                    class="font-medium text-accent hover:underline"
                                >
                                    {{ r.full_name }}
                                </Link>
                            </td>
                            <td class="px-4 py-3">
                                <SensitiveValue
                                    :masked="r.nik_masked"
                                    :reveal-url="`/kependudukan/penduduk/${r.id}/nik`"
                                    :can-reveal="can('data_pribadi.lihat_penuh')"
                                />
                            </td>
                            <td class="px-4 py-3 text-content-secondary">{{ r.gender_label }}</td>
                            <td class="px-4 py-3 text-right tabular">{{ r.age }}</td>
                            <td class="px-4 py-3 text-content-secondary">{{ r.region ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <StatusBadge :status="r.status" :label="r.status_label" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Ponsel: kartu. Tabel TIDAK PERNAH digulir horizontal
                 (docs/07-ui-ux.md §4, NFR-USA-02). -->
            <ul class="space-y-3 md:hidden">
                <li v-for="r in residents.data" :key="r.id" class="card p-4">
                    <div class="flex items-start justify-between gap-3">
                        <Link
                            :href="`/kependudukan/penduduk/${r.id}`"
                            class="font-medium text-accent hover:underline"
                        >
                            {{ r.full_name }}
                        </Link>
                        <StatusBadge :status="r.status" :label="r.status_label" />
                    </div>

                    <dl class="mt-2 space-y-1 text-sm text-content-secondary">
                        <div class="flex gap-2">
                            <dt class="sr-only">Identitas</dt>
                            <dd>{{ r.gender_label }}, {{ r.age }} tahun</dd>
                            <dd aria-hidden="true">·</dd>
                            <dd>{{ r.region ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="sr-only">NIK</dt>
                            <dd>
                                <SensitiveValue
                                    :masked="r.nik_masked"
                                    :reveal-url="`/kependudukan/penduduk/${r.id}/nik`"
                                    :can-reveal="can('data_pribadi.lihat_penuh')"
                                />
                            </dd>
                        </div>
                    </dl>
                </li>
            </ul>

            <DataPagination
                :prev-url="residents.prev_url"
                :next-url="residents.next_url"
                :count="residents.count"
                noun="penduduk"
            />
        </template>
    </AppLayout>
</template>
