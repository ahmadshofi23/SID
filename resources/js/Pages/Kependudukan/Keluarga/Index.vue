<script setup lang="ts">
import { ref, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { House, Plus, Search, FilterX } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/AppButton.vue'
import SelectField from '@/Components/SelectField.vue'
import SensitiveValue from '@/Components/SensitiveValue.vue'
import EmptyState from '@/Components/EmptyState.vue'
import DataPagination from '@/Components/DataPagination.vue'
import { usePageData } from '@/composables/usePage'
import type { CursorPage, Family } from '@/types'

const props = defineProps<{
    families: CursorPage<Family>
    q: string
    filters: Record<string, string | null>
    regions: Record<string, string>
    references: Record<string, Record<string, string>>
}>()

const { can } = usePageData()

const search = ref(props.q)
const regionId = ref(props.filters.region_id ?? '')
const welfare = ref(props.filters.welfare_status_code ?? '')

let timer: ReturnType<typeof setTimeout> | undefined

function apply(immediate = false): void {
    clearTimeout(timer)

    const run = () => router.get('/kependudukan/keluarga', {
        q: search.value || undefined,
        region_id: regionId.value || undefined,
        welfare_status_code: welfare.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true })

    immediate ? run() : (timer = setTimeout(run, 400))
}

watch(search, () => apply())
watch([regionId, welfare], () => apply(true))

function reset(): void {
    search.value = ''
    regionId.value = ''
    welfare.value = ''
    apply(true)
}

const hasFilter = () => Boolean(search.value || regionId.value || welfare.value)
</script>

<template>
    <Head title="Kartu Keluarga" />

    <AppLayout>
        <header class="mb-5 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">Kartu Keluarga</h1>
                <p class="mt-1 text-sm text-content-secondary">
                    Daftar kartu keluarga dalam lingkup akses Anda.
                </p>
            </div>

            <AppButton v-if="can('keluarga.tambah')" href="/kependudukan/keluarga/tambah" variant="primary">
                <Plus class="size-4" aria-hidden="true" />
                Tambah Kartu Keluarga
            </AppButton>
        </header>

        <section class="card mb-5 p-4" aria-label="Saringan data">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="lg:col-span-2">
                    <label for="cari-kk" class="mb-1.5 block text-sm font-medium">Cari</label>
                    <div class="relative">
                        <Search class="pointer-events-none absolute left-3 top-1/2 size-4
                                       -translate-y-1/2 text-content-tertiary" aria-hidden="true" />
                        <input
                            id="cari-kk"
                            v-model="search"
                            type="search"
                            placeholder="Nomor KK atau alamat"
                            class="h-11 w-full rounded-lg border border-line-control bg-surface pl-9 pr-3
                                   text-base transition-colors duration-200
                                   placeholder:text-content-tertiary
                                   hover:border-content-tertiary focus:border-accent focus:outline-none"
                            aria-describedby="cari-kk-hint"
                        >
                    </div>
                    <p id="cari-kk-hint" class="mt-1.5 text-sm text-content-tertiary">
                        Nomor KK dicari persis; alamat boleh sebagian.
                    </p>
                </div>

                <SelectField v-model="regionId" label="Wilayah (RT)" :options="regions"
                             placeholder="Semua wilayah" />
                <SelectField v-model="welfare" label="Kesejahteraan"
                             :options="references.welfare_statuses" placeholder="Semua" />

                <div v-if="hasFilter()" class="flex items-end">
                    <AppButton variant="ghost" @click="reset">
                        <FilterX class="size-4" aria-hidden="true" />
                        Hapus saringan
                    </AppButton>
                </div>
            </div>
        </section>

        <EmptyState
            v-if="families.data.length === 0"
            :icon="House"
            :title="hasFilter() ? 'Tidak ada data yang cocok' : 'Belum ada kartu keluarga'"
            description="Kartu keluarga dibuat lebih dulu, lalu anggotanya ditambahkan."
        >
            <AppButton v-if="hasFilter()" variant="secondary" @click="reset">Hapus saringan</AppButton>
            <AppButton v-else-if="can('keluarga.tambah')"
                       href="/kependudukan/keluarga/tambah" variant="primary">
                <Plus class="size-4" aria-hidden="true" />
                Tambah Kartu Keluarga
            </AppButton>
        </EmptyState>

        <template v-else>
            <div class="card hidden overflow-hidden md:block">
                <table class="w-full text-sm">
                    <caption class="sr-only">Daftar kartu keluarga</caption>
                    <thead>
                        <tr class="border-b border-line bg-surface-sunken text-left">
                            <th scope="col" class="px-4 py-3 font-semibold">Nomor KK</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Alamat</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Wilayah</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Anggota</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="f in families.data"
                            :key="f.id"
                            class="border-b border-line transition-colors duration-150
                                   last:border-0 hover:bg-surface-hover"
                        >
                            <td class="px-4 py-3">
                                <SensitiveValue
                                    :masked="f.kk_number_masked"
                                    :reveal-url="`/kependudukan/keluarga/${f.id}/nomor-kk`"
                                    label="Nomor KK"
                                    :can-reveal="can('data_pribadi.lihat_penuh')"
                                />
                            </td>
                            <td class="px-4 py-3">
                                <Link :href="`/kependudukan/keluarga/${f.id}`"
                                      class="font-medium text-accent hover:underline">
                                    {{ f.address }}
                                </Link>
                            </td>
                            <td class="px-4 py-3 text-content-secondary">{{ f.region ?? '—' }}</td>
                            <td class="px-4 py-3 text-right tabular">{{ f.members_count ?? 0 }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <ul class="space-y-3 md:hidden">
                <li v-for="f in families.data" :key="f.id" class="card p-4">
                    <Link :href="`/kependudukan/keluarga/${f.id}`"
                          class="font-medium text-accent hover:underline">
                        {{ f.address }}
                    </Link>
                    <div class="mt-2 space-y-1 text-sm text-content-secondary">
                        <SensitiveValue
                            :masked="f.kk_number_masked"
                            :reveal-url="`/kependudukan/keluarga/${f.id}/nomor-kk`"
                            label="Nomor KK"
                            :can-reveal="can('data_pribadi.lihat_penuh')"
                        />
                        <p>{{ f.region ?? '—' }} · {{ f.members_count ?? 0 }} anggota</p>
                    </div>
                </li>
            </ul>

            <DataPagination
                :prev-url="families.prev_url"
                :next-url="families.next_url"
                :count="families.count"
                noun="kartu keluarga"
            />
        </template>
    </AppLayout>
</template>
