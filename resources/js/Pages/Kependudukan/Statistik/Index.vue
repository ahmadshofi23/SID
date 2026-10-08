<script setup lang="ts">
import { computed } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import { RefreshCw, Users, Info } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/AppButton.vue'
import AppAlert from '@/Components/AppAlert.vue'
import SelectField from '@/Components/SelectField.vue'
import BarChart from '@/Components/BarChart.vue'
import { usePageData } from '@/composables/usePage'

interface Slice { code: string; label: string; total: number | string }

const props = defineProps<{
    stats: {
        total: number
        computed_at: string | null
        by_gender: Slice[]
        pyramid: Array<{ group: string; L: number | string; P: number | string }>
        by_religion: Slice[]
        by_education: Slice[]
        by_occupation: Slice[]
        by_marital_status: Slice[]
    }
    regions: Record<string, string>
    regionId: string | null
    suppressed: boolean
}>()

const { can } = usePageData()
const nf = new Intl.NumberFormat('id-ID')
const refreshForm = useForm({})

function changeRegion(value: string): void {
    router.get('/kependudukan/statistik', { region_id: value || undefined },
        { preserveState: true, preserveScroll: true, replace: true })
}

const computedLabel = computed(() => {
    if (!props.stats.computed_at) return 'belum pernah dihitung'

    return new Date(props.stats.computed_at).toLocaleString('id-ID', {
        day: 'numeric', month: 'long', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    })
})

function pyramidMax(): number {
    const values = props.stats.pyramid.flatMap((r) =>
        [r.L, r.P].map((v) => (typeof v === 'number' ? v : 1)))

    return Math.max(1, ...values)
}

function width(value: number | string): string {
    const n = typeof value === 'number' ? value : 1

    return `${Math.max(n > 0 ? 2 : 0, (n / pyramidMax()) * 100)}%`
}
</script>

<template>
    <Head title="Statistik Penduduk" />

    <AppLayout>
        <header class="mb-5 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">Statistik Penduduk</h1>
                <p class="mt-1 text-sm text-content-secondary">
                    Data per {{ computedLabel }}.
                </p>
            </div>

            <div class="flex flex-wrap items-end gap-3">
                <SelectField
                    :model-value="regionId"
                    label="Wilayah"
                    :options="regions"
                    placeholder="Seluruh desa"
                    class="min-w-52"
                    @update:model-value="changeRegion"
                />
                <AppButton
                    v-if="can('laporan.lihat')"
                    :loading="refreshForm.processing"
                    @click="refreshForm.post('/kependudukan/statistik/segarkan', { preserveScroll: true })"
                >
                    <RefreshCw class="size-4" aria-hidden="true" />
                    Segarkan
                </AppButton>
            </div>
        </header>

        <AppAlert v-if="suppressed" type="info" class="mb-5">
            Kelompok yang berisi kurang dari 5 orang ditampilkan sebagai
            <strong>&lt; 5</strong>. Ini mencegah seseorang dikenali dari kombinasi
            saringan yang sempit — angkanya ada, hanya sengaja tidak dirinci.
        </AppAlert>

        <div class="grid gap-5 lg:grid-cols-3">
            <article class="card p-5">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-sm text-content-secondary">Penduduk aktif</p>
                        <p class="mt-1 text-3xl font-semibold tabular">
                            {{ nf.format(stats.total) }}
                        </p>
                    </div>
                    <span class="flex size-10 items-center justify-center rounded-lg bg-accent-soft">
                        <Users class="size-5 text-accent-text" aria-hidden="true" />
                    </span>
                </div>
            </article>

            <article class="card p-5 lg:col-span-2">
                <h2 class="mb-3 font-semibold">Jenis kelamin</h2>
                <BarChart :items="stats.by_gender" caption="Jumlah penduduk menurut jenis kelamin" />
            </article>
        </div>

        <!-- Piramida penduduk -->
        <section class="card mt-5 p-5">
            <h2 class="mb-1 font-semibold">Piramida penduduk</h2>
            <p class="mb-4 text-sm text-content-tertiary">
                Kelompok umur menurut jenis kelamin.
            </p>

            <table class="w-full text-sm">
                <caption class="sr-only">Jumlah penduduk per kelompok umur dan jenis kelamin</caption>
                <thead>
                    <tr class="text-content-tertiary">
                        <th scope="col" class="pb-2 text-right font-medium">Laki-laki</th>
                        <th scope="col" class="pb-2 text-center font-medium">Umur</th>
                        <th scope="col" class="pb-2 text-left font-medium">Perempuan</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in [...stats.pyramid].reverse()" :key="row.group">
                        <td class="py-1 align-middle">
                            <div class="flex items-center justify-end gap-2">
                                <span class="tabular text-content-secondary">{{ row.L }}</span>
                                <div class="h-3 rounded-l-full bg-accent"
                                     :style="{ width: width(row.L) }" aria-hidden="true" />
                            </div>
                        </td>
                        <th scope="row" class="w-20 px-2 py-1 text-center font-medium tabular">
                            {{ row.group }}
                        </th>
                        <td class="py-1 align-middle">
                            <div class="flex items-center gap-2">
                                <div class="h-3 rounded-r-full bg-gold-500"
                                     :style="{ width: width(row.P) }" aria-hidden="true" />
                                <span class="tabular text-content-secondary">{{ row.P }}</span>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <div class="mt-5 grid gap-5 md:grid-cols-2">
            <section class="card p-5">
                <h2 class="mb-3 font-semibold">Agama</h2>
                <BarChart :items="stats.by_religion" caption="Jumlah penduduk menurut agama" />
            </section>

            <section class="card p-5">
                <h2 class="mb-3 font-semibold">Status perkawinan</h2>
                <BarChart :items="stats.by_marital_status"
                          caption="Jumlah penduduk menurut status perkawinan" />
            </section>

            <section class="card p-5">
                <h2 class="mb-3 font-semibold">Pendidikan</h2>
                <BarChart :items="stats.by_education" caption="Jumlah penduduk menurut pendidikan" />
            </section>

            <section class="card p-5">
                <h2 class="mb-1 font-semibold">Pekerjaan</h2>
                <p class="mb-3 text-sm text-content-tertiary">10 terbanyak.</p>
                <BarChart :items="stats.by_occupation" caption="Jumlah penduduk menurut pekerjaan" />
            </section>
        </div>

        <p class="mt-5 flex items-start gap-2 text-sm text-content-tertiary">
            <Info class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            Penduduk berstatus meninggal dan pindah keluar tidak dihitung.
            Angka disegarkan otomatis tiap jam, dan langsung setelah impor atau
            pencatatan mutasi.
        </p>
    </AppLayout>
</template>
