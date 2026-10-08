<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { Users, House, MapPin, ArrowRight, TriangleAlert } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppAlert from '@/Components/AppAlert.vue'
import { usePageData } from '@/composables/usePage'

defineProps<{
    stats: {
        residents_active: number
        residents_total: number
        families: number
    } | null
}>()

const { user, can } = usePageData()

const nf = new Intl.NumberFormat('id-ID')
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout>
        <header class="mb-6">
            <h1 class="text-2xl font-semibold">Dashboard</h1>
            <p class="mt-1 text-sm text-content-secondary">
                Selamat datang, {{ user?.name }}.
            </p>
        </header>

        <!-- Kepala Dusun tanpa penugasan wilayah tidak melihat data apa pun.
             Tanpa penjelasan ini, ia akan mengira sistemnya rusak. -->
        <AppAlert
            v-if="user?.region_limited && !user.region_assigned"
            type="warning"
            title="Wilayah Anda belum ditetapkan"
            class="mb-6"
        >
            Anda belum ditugaskan ke wilayah mana pun, sehingga belum dapat melihat
            data penduduk. Hubungi Admin Desa untuk penugasan wilayah.
        </AppAlert>

        <!-- KPI: angka besar, label kecil, satu baris konteks di bawahnya -->
        <div v-if="stats" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <article class="card p-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm text-content-secondary">Penduduk aktif</p>
                        <p class="mt-1 text-3xl font-semibold tabular">
                            {{ nf.format(stats.residents_active) }}
                        </p>
                        <p class="mt-1 text-sm text-content-tertiary">
                            dari {{ nf.format(stats.residents_total) }} tercatat
                        </p>
                    </div>
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-accent-soft">
                        <Users class="size-5 text-accent-text" aria-hidden="true" />
                    </span>
                </div>

                <Link
                    v-if="can('penduduk.lihat')"
                    href="/kependudukan/penduduk"
                    class="mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-accent
                           transition-opacity hover:opacity-80"
                >
                    Lihat data penduduk
                    <ArrowRight class="size-4" aria-hidden="true" />
                </Link>
            </article>

            <article v-if="can('keluarga.lihat')" class="card p-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm text-content-secondary">Kartu keluarga</p>
                        <p class="mt-1 text-3xl font-semibold tabular">
                            {{ nf.format(stats.families) }}
                        </p>
                        <p class="mt-1 text-sm text-content-tertiary">dalam lingkup akses Anda</p>
                    </div>
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-accent-soft">
                        <House class="size-5 text-accent-text" aria-hidden="true" />
                    </span>
                </div>

                <Link
                    href="/kependudukan/keluarga"
                    class="mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-accent
                           transition-opacity hover:opacity-80"
                >
                    Lihat kartu keluarga
                    <ArrowRight class="size-4" aria-hidden="true" />
                </Link>
            </article>

            <article class="card p-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm text-content-secondary">Lingkup akses Anda</p>
                        <p class="mt-1 text-xl font-semibold">
                            {{ user?.region_limited
                                ? (user.region_assigned ? 'Wilayah tertentu' : 'Belum ditetapkan')
                                : 'Seluruh desa' }}
                        </p>
                        <p class="mt-1 text-sm text-content-tertiary">
                            {{ user?.role_labels.join(', ') }}
                        </p>
                    </div>
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-lg"
                          :class="user?.region_limited && !user.region_assigned
                              ? 'bg-warning-soft' : 'bg-accent-soft'">
                        <TriangleAlert
                            v-if="user?.region_limited && !user.region_assigned"
                            class="size-5 text-warning" aria-hidden="true"
                        />
                        <MapPin v-else class="size-5 text-accent-text" aria-hidden="true" />
                    </span>
                </div>
            </article>
        </div>

        <section class="card mt-6 p-5">
            <h2 class="font-semibold">Pekerjaan berikutnya</h2>
            <p class="mt-1.5 text-sm text-content-secondary">
                Modul kependudukan sudah dapat dipakai. Impor data massal dari Excel,
                pencatatan mutasi (lahir, mati, pindah), dan statistik dijadwalkan pada
                Sprint 3; layanan persuratan pada Sprint 4–5.
            </p>
        </section>
    </AppLayout>
</template>
