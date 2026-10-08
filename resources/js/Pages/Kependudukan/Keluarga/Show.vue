<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { ChevronRight, Pencil, Plus, Users, History } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/AppButton.vue'
import AppAlert from '@/Components/AppAlert.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import SensitiveValue from '@/Components/SensitiveValue.vue'
import { usePageData } from '@/composables/usePage'
import type { Family, HistoryEntry } from '@/types'

const props = defineProps<{
    family: Family
    members: Array<{
        id: string; full_name: string; relation: string; age: number
        gender_label: string; status: string; status_label: string; is_head: boolean
    }>
    history: HistoryEntry[]
}>()

const { can } = usePageData()

const hasHead = () => props.members.some((m) => m.is_head)
</script>

<template>
    <Head title="Kartu Keluarga" />

    <AppLayout>
        <nav class="mb-4 flex items-center gap-1 text-sm text-content-tertiary" aria-label="Remah roti">
            <Link href="/kependudukan/keluarga" class="hover:text-content-secondary hover:underline">
                Kartu Keluarga
            </Link>
            <ChevronRight class="size-3.5" aria-hidden="true" />
            <span class="font-mono text-content-secondary">{{ family.kk_number_masked }}</span>
        </nav>

        <header class="mb-5 flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <h1 class="text-2xl font-semibold">{{ family.address }}</h1>
                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-content-secondary">
                    <SensitiveValue
                        :masked="family.kk_number_masked"
                        :reveal-url="`/kependudukan/keluarga/${family.id}/nomor-kk`"
                        label="Nomor KK"
                        :can-reveal="can('data_pribadi.lihat_penuh')"
                    />
                    <span aria-hidden="true">·</span>
                    <span>{{ family.region_full ?? 'Wilayah belum ditetapkan' }}</span>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <AppButton v-if="can('keluarga.ubah')"
                           :href="`/kependudukan/keluarga/${family.id}/ubah`" variant="secondary">
                    <Pencil class="size-4" aria-hidden="true" />
                    Ubah
                </AppButton>
                <AppButton v-if="can('penduduk.tambah')"
                           :href="`/kependudukan/penduduk/tambah?family_id=${family.id}`"
                           variant="primary">
                    <Plus class="size-4" aria-hidden="true" />
                    Tambah Anggota
                </AppButton>
            </div>
        </header>

        <AppAlert v-if="members.length > 0 && !hasHead()" type="warning"
                  title="Belum ada kepala keluarga" class="mb-5">
            Kartu keluarga ini belum memiliki kepala keluarga. Tetapkan salah satu
            anggota sebagai kepala keluarga agar data administrasi lengkap.
        </AppAlert>

        <div class="grid gap-5 lg:grid-cols-3">
            <section class="card p-5 lg:col-span-2">
                <div class="mb-4 flex items-center gap-2">
                    <Users class="size-4 text-content-tertiary" aria-hidden="true" />
                    <h2 class="font-semibold">Anggota keluarga ({{ members.length }})</h2>
                </div>

                <template v-if="members.length === 0">
                    <p class="text-sm text-content-tertiary">
                        Belum ada anggota. Tambahkan kepala keluarga terlebih dahulu.
                    </p>
                    <AppButton v-if="can('penduduk.tambah')"
                               :href="`/kependudukan/penduduk/tambah?family_id=${family.id}`"
                               variant="primary" size="sm" class="mt-4">
                        <Plus class="size-4" aria-hidden="true" />
                        Tambah Anggota
                    </AppButton>
                </template>

                <ul v-else class="divide-y divide-[var(--color-line)]">
                    <li v-for="m in members" :key="m.id"
                        class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                        <div class="min-w-0">
                            <Link :href="`/kependudukan/penduduk/${m.id}`"
                                  class="font-medium text-accent hover:underline">
                                {{ m.full_name }}
                            </Link>
                            <p class="text-sm text-content-tertiary">
                                {{ m.relation }}
                                <span v-if="m.is_head"
                                      class="ml-1 rounded bg-accent-soft px-1.5 py-0.5 text-xs
                                             font-medium text-accent-text">
                                    Kepala keluarga
                                </span>
                                · {{ m.gender_label }}, {{ m.age }} tahun
                            </p>
                        </div>
                        <StatusBadge :status="m.status" :label="m.status_label" />
                    </li>
                </ul>
            </section>

            <aside class="space-y-5">
                <section class="card p-5">
                    <h2 class="mb-4 font-semibold">Keterangan</h2>
                    <dl class="space-y-3 text-sm">
                        <div>
                            <dt class="text-content-tertiary">RT / RW</dt>
                            <dd>{{ family.rt || '—' }} / {{ family.rw || '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-content-tertiary">Status kesejahteraan</dt>
                            <dd>{{ family.welfare_status || '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-content-tertiary">Kepemilikan rumah</dt>
                            <dd>{{ family.house_ownership || '—' }}</dd>
                        </div>
                    </dl>
                </section>

                <section class="card p-5">
                    <div class="mb-3 flex items-center gap-2">
                        <History class="size-4 text-content-tertiary" aria-hidden="true" />
                        <h2 class="font-semibold">Riwayat</h2>
                    </div>

                    <p v-if="history.length === 0" class="text-sm text-content-tertiary">
                        Belum ada riwayat tercatat.
                    </p>

                    <ol v-else class="space-y-3">
                        <li v-for="(entry, i) in history" :key="i" class="text-sm">
                            <p class="font-medium">{{ entry.aksi }}</p>
                            <p class="text-xs text-content-tertiary">
                                {{ entry.pelaku }} · {{ entry.waktu }}
                            </p>
                        </li>
                    </ol>
                </section>
            </aside>
        </div>
    </AppLayout>
</template>
