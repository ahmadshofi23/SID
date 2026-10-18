<script setup lang="ts">
import { ref, watch } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { Newspaper, Plus, Pencil, Clock, ExternalLink, CircleCheck, CircleDot, Archive } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/AppButton.vue'
import AppAlert from '@/Components/AppAlert.vue'
import SelectField from '@/Components/SelectField.vue'
import EmptyState from '@/Components/EmptyState.vue'
import DataPagination from '@/Components/DataPagination.vue'
import { usePageData } from '@/composables/usePage'
import type { CursorPage } from '@/types'

interface PostRow {
    id: string
    type: string
    title: string
    slug: string
    status: string
    is_scheduled: boolean
    published_at: string | null
    event_start_at: string | null
    author_name: string | null
    view_count: number
    updated_at: string | null
}

const props = defineProps<{
    posts: CursorPage<PostRow>
    filters: { jenis: string; status: string }
    typeLabels: Record<string, string>
    counts: { draft: number; terjadwal: number }
}>()

const { can } = usePageData()

const jenis = ref(props.filters.jenis ?? '')
const status = ref(props.filters.status ?? '')

watch([jenis, status], () => {
    router.get('/portal/konten', {
        jenis: jenis.value || undefined,
        status: status.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true })
})

const statusLabel: Record<string, string> = {
    draft: 'Draf', terbit: 'Terbit', arsip: 'Arsip',
}

/*
 * Terjadwal adalah TERBIT yang tanggalnya belum tiba. Dibedakan di layar
 * karena bagi petugas keduanya berbeda jauh: yang satu sudah dibaca warga,
 * yang satu belum terlihat siapa pun.
 *
 * Setiap keadaan punya IKON dan TEKS, bukan warna saja: sekitar 1 dari 12
 * laki-laki mengalami buta warna (WCAG color-not-only), dan StatusBadge yang
 * sudah ada khusus untuk status penduduk, bukan status terbit.
 */
function badge(row: PostRow) {
    if (row.status === 'terbit' && row.is_scheduled) {
        return { cls: 'bg-info-soft text-info', icon: Clock, label: 'Terjadwal' }
    }
    if (row.status === 'terbit') {
        return { cls: 'bg-success-soft text-success', icon: CircleCheck, label: 'Terbit' }
    }
    if (row.status === 'arsip') {
        return { cls: 'bg-surface-hover text-content-secondary', icon: Archive, label: 'Arsip' }
    }
    return { cls: 'bg-warning-soft text-warning', icon: CircleDot, label: 'Draf' }
}

function tanggal(iso: string | null): string {
    if (!iso) return '—'
    return new Date(iso).toLocaleDateString('id-ID', {
        day: 'numeric', month: 'short', year: 'numeric',
    })
}

function waktu(iso: string | null): string {
    if (!iso) return '—'
    return new Date(iso).toLocaleString('id-ID', {
        day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    })
}
</script>

<template>
    <Head title="Konten Portal" />

    <AppLayout>
        <header class="mb-5 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">Konten Portal</h1>
                <p class="mt-1 text-sm text-content-secondary">
                    Berita, pengumuman, dan agenda yang tampil di situs desa.
                </p>
            </div>

            <AppButton v-if="can('portal.kelola')" href="/portal/konten/tambah" variant="primary">
                <Plus class="size-4" aria-hidden="true" />
                Tulis Baru
            </AppButton>
        </header>

        <AppAlert v-if="!can('portal.terbitkan')" type="info" class="mb-5"
                  title="Anda dapat menulis, penerbitan dilakukan pimpinan">
            Simpan tulisan sebagai <strong>draf</strong>. Penerbitan ke situs desa
            dilakukan Kepala Desa atau Admin Desa — situs resmi adalah pernyataan
            pemerintah desa, bukan papan pengumuman terbuka.
        </AppAlert>

        <AppAlert v-if="counts.terjadwal > 0" type="info" class="mb-5" title="Ada tulisan terjadwal">
            {{ counts.terjadwal }} tulisan sudah disetujui terbit tetapi tanggalnya
            belum tiba, sehingga belum terlihat warga.
        </AppAlert>

        <div class="card mb-5 grid gap-4 p-4 sm:grid-cols-2 lg:max-w-xl">
            <SelectField v-model="jenis" label="Jenis" placeholder="— Semua jenis —"
                         :options="typeLabels" />
            <SelectField v-model="status" label="Status" placeholder="— Semua status —"
                         :options="statusLabel" />
        </div>

        <EmptyState
            v-if="posts.data.length === 0"
            :icon="Newspaper"
            title="Belum ada tulisan"
            description="Berita, pengumuman, dan agenda yang Anda tulis akan muncul di sini."
        >
            <AppButton v-if="can('portal.kelola')" href="/portal/konten/tambah" variant="primary">
                <Plus class="size-4" aria-hidden="true" />
                Tulis Baru
            </AppButton>
        </EmptyState>

        <div v-else class="card overflow-hidden">
            <table class="hidden w-full text-sm md:table">
                <caption class="sr-only">Daftar konten portal</caption>
                <thead class="bg-surface-sunken text-left text-xs uppercase tracking-wide text-content-tertiary">
                    <tr>
                        <th scope="col" class="w-2/5 px-4 py-3 font-medium">Judul</th>
                        <th scope="col" class="px-4 py-3 font-medium">Jenis</th>
                        <th scope="col" class="px-4 py-3 font-medium">Status</th>
                        <th scope="col" class="px-4 py-3 font-medium">Terbit</th>
                        <th scope="col" class="px-4 py-3 font-medium">Penulis</th>
                        <th scope="col" class="px-4 py-3 font-medium"><span class="sr-only">Tindakan</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in posts.data" :key="row.id"
                        class="border-t border-line transition-colors hover:bg-surface-hover">
                        <td class="px-4 py-3">
                            <span class="font-medium">{{ row.title }}</span>
                            <span v-if="row.event_start_at" class="mt-0.5 block text-xs text-content-tertiary">
                                Kegiatan: {{ waktu(row.event_start_at) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-content-secondary">{{ typeLabels[row.type] }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-xs font-medium whitespace-nowrap"
                                  :class="badge(row).cls">
                                <component :is="badge(row).icon" class="size-3.5 shrink-0" aria-hidden="true" />
                                {{ badge(row).label }}
                            </span>
                        </td>
                        <td class="tabular px-4 py-3 text-content-secondary">
                            <span class="inline-flex items-center gap-1">
                                <Clock v-if="row.is_scheduled" class="size-3.5" aria-hidden="true" />
                                {{ tanggal(row.published_at) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-content-secondary">{{ row.author_name ?? '—' }}</td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex justify-end gap-1">
                                <AppButton v-if="row.status === 'terbit' && !row.is_scheduled"
                                           variant="ghost" size="sm"
                                           :href="`/${row.type}/${row.slug}`">
                                    <ExternalLink class="size-3.5" aria-hidden="true" />
                                    Lihat
                                </AppButton>
                                <AppButton v-if="can('portal.kelola')" variant="ghost" size="sm"
                                           :href="`/portal/konten/${row.id}/ubah`">
                                    <Pencil class="size-3.5" aria-hidden="true" />
                                    Ubah
                                </AppButton>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Tampilan kartu untuk ponsel: tabel enam kolom tidak terbaca di 320px -->
            <ul class="divide-y divide-line md:hidden">
                <li v-for="row in posts.data" :key="row.id" class="p-4">
                    <div class="flex items-start justify-between gap-3">
                        <span class="font-medium">{{ row.title }}</span>
                        <span class="inline-flex shrink-0 items-center gap-1.5 rounded-md px-2 py-0.5 text-xs font-medium whitespace-nowrap"
                              :class="badge(row).cls">
                            <component :is="badge(row).icon" class="size-3.5 shrink-0" aria-hidden="true" />
                            {{ badge(row).label }}
                        </span>
                    </div>
                    <p class="mt-1 text-xs text-content-tertiary">
                        {{ typeLabels[row.type] }} · {{ tanggal(row.published_at) }}
                    </p>
                    <AppButton v-if="can('portal.kelola')" variant="ghost" size="sm" class="mt-2"
                               :href="`/portal/konten/${row.id}/ubah`">
                        <Pencil class="size-3.5" aria-hidden="true" />
                        Ubah
                    </AppButton>
                </li>
            </ul>
        </div>

        <DataPagination
            v-if="posts.data.length > 0"
            :prev-url="posts.prev_url" :next-url="posts.next_url"
            :count="posts.count" noun="tulisan"
        />
    </AppLayout>
</template>
