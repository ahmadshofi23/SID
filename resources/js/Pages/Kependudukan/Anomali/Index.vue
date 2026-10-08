<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { ShieldCheck, TriangleAlert, Info, CircleAlert } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppAlert from '@/Components/AppAlert.vue'

interface Check {
    label: string
    severity: 'tinggi' | 'sedang' | 'rendah'
    description: string
    count: number
    items: Array<Record<string, unknown>>
}

defineProps<{ report: Record<string, Check> }>()

const severityStyle: Record<string, { cls: string; icon: typeof Info; label: string }> = {
    tinggi: { cls: 'bg-danger-soft text-danger', icon: CircleAlert, label: 'Perlu segera' },
    sedang: { cls: 'bg-warning-soft text-warning', icon: TriangleAlert, label: 'Perlu diperiksa' },
    rendah: { cls: 'bg-info-soft text-info', icon: Info, label: 'Informasi' },
}

function residents(item: Record<string, unknown>): Array<Record<string, string>> {
    return Array.isArray(item.penduduk) ? item.penduduk as Array<Record<string, string>> : []
}
</script>

<template>
    <Head title="Anomali Data" />

    <AppLayout>
        <header class="mb-5">
            <h1 class="text-2xl font-semibold">Anomali Data</h1>
            <p class="mt-1 text-sm text-content-secondary">
                Pemeriksaan otomatis atas data yang kemungkinan keliru.
            </p>
        </header>

        <AppAlert type="info" class="mb-5">
            Pencarian nama di sistem ini mencocokkan huruf per huruf, sehingga nama
            yang dieja sedikit berbeda bisa lolos dan menjadi data ganda. Halaman
            inilah jaring pengamannya — periksa secara berkala, terutama setelah
            impor data.
        </AppAlert>

        <div class="space-y-5">
            <section v-for="(check, key) in report" :key="key" class="card p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="font-semibold">{{ check.label }}</h2>
                            <span class="inline-flex items-center gap-1 rounded-md px-2 py-0.5
                                         text-xs font-medium"
                                  :class="severityStyle[check.severity].cls">
                                <component :is="severityStyle[check.severity].icon"
                                           class="size-3.5" aria-hidden="true" />
                                {{ severityStyle[check.severity].label }}
                            </span>
                        </div>
                        <p class="mt-1.5 max-w-2xl text-sm text-content-secondary">
                            {{ check.description }}
                        </p>
                    </div>

                    <span class="shrink-0 text-2xl font-semibold tabular"
                          :class="check.count > 0 ? 'text-content' : 'text-content-tertiary'">
                        {{ check.count }}
                    </span>
                </div>

                <p v-if="check.count === 0"
                   class="mt-4 flex items-center gap-2 text-sm text-success">
                    <ShieldCheck class="size-4" aria-hidden="true" />
                    Tidak ditemukan masalah.
                </p>

                <ul v-else class="mt-4 divide-y divide-[var(--color-line)] border-t border-line">
                    <li v-for="(item, i) in check.items.slice(0, 10)" :key="i" class="py-3">
                        <!-- Kelompok (NIK ganda / nama mirip) -->
                        <template v-if="residents(item).length > 0">
                            <p class="text-sm font-medium">
                                {{ item.nama ?? item.nik }}
                                <span v-if="item.wilayah" class="font-normal text-content-tertiary">
                                    · {{ item.wilayah }}
                                </span>
                            </p>
                            <ul class="mt-1 space-y-0.5">
                                <li v-for="r in residents(item)" :key="r.id" class="text-sm">
                                    <Link :href="`/kependudukan/penduduk/${r.id}`"
                                          class="text-accent hover:underline">{{ r.nama }}</Link>
                                    <span class="text-content-tertiary">
                                        · lahir {{ r.lahir }}<template v-if="r.nik"> · {{ r.nik }}</template>
                                    </span>
                                </li>
                            </ul>
                        </template>

                        <!-- Kartu keluarga -->
                        <template v-else-if="item.family_id">
                            <Link :href="`/kependudukan/keluarga/${item.family_id}`"
                                  class="text-sm font-medium text-accent hover:underline">
                                {{ item.nomor_kk }}
                            </Link>
                            <span class="text-sm text-content-tertiary">
                                · {{ item.alamat }} · {{ item.jumlah_anggota }} anggota
                            </span>
                        </template>

                        <!-- Penduduk tunggal -->
                        <template v-else>
                            <Link :href="`/kependudukan/penduduk/${item.id}`"
                                  class="text-sm font-medium text-accent hover:underline">
                                {{ item.nama }}
                            </Link>
                            <span class="text-sm text-content-tertiary">
                                <template v-if="item.nik"> · {{ item.nik }}</template>
                                <template v-if="item.umur !== undefined"> · {{ item.umur }} tahun</template>
                                <template v-if="item.lahir"> · lahir {{ item.lahir }}</template>
                                <template v-if="item.alamat"> · {{ item.alamat }}</template>
                            </span>
                        </template>
                    </li>
                </ul>

                <p v-if="check.count > 10" class="mt-3 text-sm text-content-tertiary">
                    Menampilkan 10 dari {{ check.count }}. Perbaiki yang tampil lebih
                    dulu, lalu muat ulang halaman ini.
                </p>
            </section>
        </div>
    </AppLayout>
</template>
