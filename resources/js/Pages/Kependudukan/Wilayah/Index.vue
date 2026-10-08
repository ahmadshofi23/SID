<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { Map, Plus, Pencil } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/AppButton.vue'
import AppAlert from '@/Components/AppAlert.vue'
import EmptyState from '@/Components/EmptyState.vue'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
import { usePageData } from '@/composables/usePage'
import type { Region } from '@/types'

defineProps<{ tree: Region[] }>()

const { can } = usePageData()

const levelLabel: Record<string, string> = { dusun: 'Dusun', rw: 'RW', rt: 'RT' }
</script>

<template>
    <Head title="Wilayah" />

    <AppLayout>
        <header class="mb-5 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">Wilayah</h1>
                <p class="mt-1 text-sm text-content-secondary">Struktur Dusun → RW → RT.</p>
            </div>

            <AppButton v-if="can('wilayah.kelola')" href="/kependudukan/wilayah/tambah" variant="primary">
                <Plus class="size-4" aria-hidden="true" />
                Tambah Wilayah
            </AppButton>
        </header>

        <AppAlert type="info" title="Mengapa struktur ini penting" class="mb-5">
            Wilayah bukan sekadar label alamat. Ia menentukan
            <strong>data siapa yang dapat dilihat Kepala Dusun</strong>. Memindahkan
            atau mengubah wilayah langsung mengubah hak akses — periksa kembali
            sebelum menyimpan.
        </AppAlert>

        <EmptyState
            v-if="tree.length === 0"
            :icon="Map"
            title="Belum ada wilayah"
            description="Mulai dengan menambahkan dusun, lalu RW, lalu RT."
        >
            <AppButton v-if="can('wilayah.kelola')" href="/kependudukan/wilayah/tambah" variant="primary">
                <Plus class="size-4" aria-hidden="true" />
                Tambah Dusun
            </AppButton>
        </EmptyState>

        <div v-else class="card p-5">
            <ul class="space-y-1">
                <li v-for="dusun in tree" :key="dusun.id">
                    <div class="flex flex-wrap items-center gap-2 rounded-lg px-2 py-2
                                transition-colors hover:bg-surface-hover">
                        <span class="rounded bg-accent-soft px-1.5 py-0.5 text-xs font-medium text-accent-text">
                            {{ levelLabel[dusun.level] }}
                        </span>
                        <span class="font-medium">{{ dusun.name }}</span>
                        <span v-if="dusun.head_name" class="text-sm text-content-tertiary">
                            Kepala: {{ dusun.head_name }}
                        </span>
                        <AppButton v-if="can('wilayah.kelola')" variant="ghost" size="sm"
                                   :href="`/kependudukan/wilayah/${dusun.id}/ubah`" class="ml-auto">
                            <Pencil class="size-3.5" aria-hidden="true" />
                            Ubah
                        </AppButton>
                    </div>

                    <ul v-if="dusun.children?.length"
                        class="ml-4 border-l border-line pl-4 sm:ml-5 sm:pl-5">
                        <li v-for="rw in dusun.children" :key="rw.id" class="mt-1">
                            <div class="flex flex-wrap items-center gap-2 rounded-lg px-2 py-2
                                        transition-colors hover:bg-surface-hover">
                                <span class="rounded bg-surface-hover px-1.5 py-0.5 text-xs font-medium
                                             text-content-secondary">
                                    {{ levelLabel[rw.level] }}
                                </span>
                                <span class="font-medium">{{ rw.name }}</span>
                                <AppButton v-if="can('wilayah.kelola')" variant="ghost" size="sm"
                                           :href="`/kependudukan/wilayah/${rw.id}/ubah`" class="ml-auto">
                                    <Pencil class="size-3.5" aria-hidden="true" />
                                    Ubah
                                </AppButton>
                            </div>

                            <ul v-if="rw.children?.length"
                                class="ml-4 border-l border-line pl-4 sm:ml-5 sm:pl-5">
                                <li v-for="rt in rw.children" :key="rt.id" class="mt-1">
                                    <div class="flex flex-wrap items-center gap-2 rounded-lg px-2 py-2
                                                transition-colors hover:bg-surface-hover">
                                        <span class="rounded bg-surface-hover px-1.5 py-0.5 text-xs
                                                     font-medium text-content-secondary">
                                            {{ levelLabel[rt.level] }}
                                        </span>
                                        <span>{{ rt.name }}</span>
                                        <span v-if="rt.head_name" class="text-sm text-content-tertiary">
                                            Ketua: {{ rt.head_name }}
                                        </span>

                                        <span v-if="can('wilayah.kelola')" class="ml-auto flex gap-1">
                                            <AppButton variant="ghost" size="sm"
                                                       :href="`/kependudukan/wilayah/${rt.id}/ubah`">
                                                <Pencil class="size-3.5" aria-hidden="true" />
                                                Ubah
                                            </AppButton>
                                            <ConfirmDialog
                                                :action="`/kependudukan/wilayah/${rt.id}`"
                                                :title="`Hapus ${rt.name}?`"
                                                body="Wilayah hanya dapat dihapus bila tidak dihuni penduduk dan tidak memiliki wilayah di bawahnya."
                                                confirm-label="Ya, hapus wilayah"
                                                :reason-required="false"
                                            />
                                        </span>
                                    </div>
                                </li>
                            </ul>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
