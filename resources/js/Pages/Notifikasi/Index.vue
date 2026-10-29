<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import { Bell, CheckCheck, FileText, Mail, CircleX } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import WargaLayout from '@/Layouts/WargaLayout.vue'
import AppButton from '@/Components/AppButton.vue'
import EmptyState from '@/Components/EmptyState.vue'
import DataPagination from '@/Components/DataPagination.vue'
import type { CursorPage, NotifikasiRow } from '@/types'

const props = defineProps<{
    notifikasi: CursorPage<NotifikasiRow>
    belum_dibaca: number
    untuk_warga: boolean
}>()

/* Satu layar, dua kerangka: petugas tetap melihat menu panelnya, warga
   tetap melihat bilah layanan mandiri. Isinya sama persis. */
const Layout = props.untuk_warga ? WargaLayout : AppLayout

const bacaSemua = useForm({})

function buka(id: string): void {
    // Menandai terbaca DAN mengantar ke halamannya adalah satu tindakan,
    // supaya daftar tidak terus penuh penanda merah walau sudah dibaca.
    router.post(`/notifikasi/${id}/baca`)
}

const ikon: Record<string, typeof FileText> = {
    'surat.status': FileText,
    'surat.antrean': Mail,
    'surat.dibatalkan': CircleX,
}

function waktu(iso: string | null): string {
    if (!iso) return ''
    return new Date(iso).toLocaleString('id-ID', {
        day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    })
}
</script>

<template>
    <Head title="Notifikasi" />

    <component :is="Layout">
        <header class="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">Notifikasi</h1>
                <p class="mt-1 text-sm text-content-secondary">
                    {{ belum_dibaca > 0
                        ? `${belum_dibaca} belum dibaca.`
                        : 'Semuanya sudah dibaca.' }}
                </p>
            </div>

            <AppButton v-if="belum_dibaca > 0" variant="secondary"
                       :loading="bacaSemua.processing"
                       @click="bacaSemua.post('/notifikasi/baca-semua', { preserveScroll: true })">
                <CheckCheck class="size-4" aria-hidden="true" />
                Tandai semua dibaca
            </AppButton>
        </header>

        <EmptyState
            v-if="notifikasi.data.length === 0"
            :icon="Bell"
            title="Belum ada notifikasi"
            :description="untuk_warga
                ? 'Kabar tentang pengajuan surat Anda akan muncul di sini.'
                : 'Pengajuan daring yang menunggu pemeriksaan Anda akan muncul di sini.'"
        />

        <ul v-else class="space-y-2">
            <li v-for="n in notifikasi.data" :key="n.id">
                <button
                    type="button"
                    class="card flex w-full gap-3 p-4 text-left transition-colors hover:bg-surface-hover"
                    :class="n.dibaca ? '' : 'border-accent/40 bg-accent-soft/20'"
                    @click="buka(n.id)"
                >
                    <component :is="ikon[n.kategori] ?? Bell"
                               class="mt-0.5 size-5 shrink-0"
                               :class="n.dibaca ? 'text-content-tertiary' : 'text-accent'"
                               aria-hidden="true" />

                    <span class="min-w-0 flex-1">
                        <span class="flex flex-wrap items-baseline gap-x-2">
                            <span class="font-medium" :class="n.dibaca ? '' : 'text-content'">
                                {{ n.judul }}
                            </span>
                            <!-- Penanda belum dibaca punya TEKS, bukan warna saja (WCAG 1.4.1) -->
                            <span v-if="!n.dibaca"
                                  class="rounded bg-accent-soft px-1.5 py-0.5 text-[0.625rem] font-semibold text-accent-text">
                                Baru
                            </span>
                        </span>

                        <span class="mt-1 block text-sm text-content-secondary">{{ n.isi }}</span>

                        <span class="tabular mt-1.5 block text-xs text-content-tertiary">
                            {{ waktu(n.created_at) }}
                            <span v-if="n.tiket"> · {{ n.tiket }}</span>
                            <span v-else-if="n.nomor_surat"> · {{ n.nomor_surat }}</span>
                        </span>
                    </span>
                </button>
            </li>
        </ul>

        <DataPagination
            v-if="notifikasi.data.length > 0"
            :prev-url="notifikasi.prev_url" :next-url="notifikasi.next_url"
            :count="notifikasi.count" noun="notifikasi"
        />
    </component>
</template>
