<script setup lang="ts">
import { ref, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { ArrowRightLeft, FilterX } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/AppButton.vue'
import SelectField from '@/Components/SelectField.vue'
import TextField from '@/Components/TextField.vue'
import EmptyState from '@/Components/EmptyState.vue'
import DataPagination from '@/Components/DataPagination.vue'
import type { CursorPage } from '@/types'

interface Mutation {
    id: string
    type: string
    type_label: string
    date: string
    resident_id: string
    resident_name: string
    resident_nik: string
    reason: string | null
}

const props = defineProps<{
    mutations: CursorPage<Mutation>
    filters: Record<string, string | null>
    types: Record<string, string>
}>()

const type = ref(props.filters.type ?? '')
const from = ref(props.filters.from ?? '')
const to = ref(props.filters.to ?? '')

function apply(): void {
    router.get('/kependudukan/mutasi', {
        type: type.value || undefined,
        from: from.value || undefined,
        to: to.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true })
}

watch([type, from, to], apply)

function reset(): void {
    type.value = ''; from.value = ''; to.value = ''
    apply()
}

const badge: Record<string, string> = {
    lahir: 'bg-success-soft text-success',
    mati: 'bg-surface-hover text-content-secondary',
    pindah_datang: 'bg-info-soft text-info',
    pindah_keluar: 'bg-warning-soft text-warning',
    perubahan_data: 'bg-surface-hover text-content-secondary',
}
</script>

<template>
    <Head title="Mutasi Penduduk" />

    <AppLayout>
        <header class="mb-5">
            <h1 class="text-2xl font-semibold">Mutasi Penduduk</h1>
            <p class="mt-1 text-sm text-content-secondary">
                Kelahiran, kematian, dan perpindahan. Inilah yang menjaga jumlah
                penduduk tetap sesuai kenyataan.
            </p>
        </header>

        <section class="card mb-5 p-4" aria-label="Saringan">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <SelectField v-model="type" label="Jenis mutasi" :options="types"
                             placeholder="Semua jenis" />
                <TextField v-model="from" label="Dari tanggal" type="date" />
                <TextField v-model="to" label="Sampai tanggal" type="date" />
                <div v-if="type || from || to" class="flex items-end">
                    <AppButton variant="ghost" @click="reset">
                        <FilterX class="size-4" aria-hidden="true" />
                        Hapus saringan
                    </AppButton>
                </div>
            </div>
        </section>

        <EmptyState
            v-if="mutations.data.length === 0"
            :icon="ArrowRightLeft"
            title="Belum ada mutasi tercatat"
            description="Mutasi dicatat dari halaman detail penduduk — tombol “Catat Mutasi”."
        />

        <template v-else>
            <div class="card hidden overflow-hidden md:block">
                <table class="w-full text-sm">
                    <caption class="sr-only">Daftar mutasi penduduk</caption>
                    <thead>
                        <tr class="border-b border-line bg-surface-sunken text-left">
                            <th scope="col" class="px-4 py-3 font-semibold">Tanggal</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Jenis</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Penduduk</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Catatan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="m in mutations.data" :key="m.id"
                            class="border-b border-line transition-colors last:border-0 hover:bg-surface-hover">
                            <td class="px-4 py-3 whitespace-nowrap">{{ m.date }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-md px-2 py-0.5 text-xs font-medium"
                                      :class="badge[m.type] ?? 'bg-surface-hover'">
                                    {{ m.type_label }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <Link :href="`/kependudukan/penduduk/${m.resident_id}`"
                                      class="font-medium text-accent hover:underline">
                                    {{ m.resident_name }}
                                </Link>
                                <span class="block font-mono text-xs text-content-tertiary">
                                    {{ m.resident_nik }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-content-secondary">{{ m.reason || '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <ul class="space-y-3 md:hidden">
                <li v-for="m in mutations.data" :key="m.id" class="card p-4">
                    <div class="flex items-start justify-between gap-3">
                        <Link :href="`/kependudukan/penduduk/${m.resident_id}`"
                              class="font-medium text-accent hover:underline">
                            {{ m.resident_name }}
                        </Link>
                        <span class="shrink-0 rounded-md px-2 py-0.5 text-xs font-medium"
                              :class="badge[m.type] ?? 'bg-surface-hover'">
                            {{ m.type_label }}
                        </span>
                    </div>
                    <p class="mt-1 text-sm text-content-secondary">{{ m.date }}</p>
                    <p v-if="m.reason" class="mt-1 text-sm text-content-secondary">{{ m.reason }}</p>
                </li>
            </ul>

            <DataPagination :prev-url="mutations.prev_url" :next-url="mutations.next_url"
                            :count="mutations.count" noun="mutasi" />
        </template>
    </AppLayout>
</template>
