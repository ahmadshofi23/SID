<script setup lang="ts">
import { ref } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { Upload, Download, FileSpreadsheet, ChevronRight } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/AppButton.vue'
import AppAlert from '@/Components/AppAlert.vue'
import EmptyState from '@/Components/EmptyState.vue'

interface Job {
    id: string
    original_name: string
    status: string
    status_label: string
    total_rows: number
    created_count: number
    updated_count: number
    created_at: string
}

defineProps<{
    jobs: Job[]
    columns: Record<string, { judul: string; wajib: boolean; keterangan: string }>
}>()

const form = useForm<{ file: File | null }>({ file: null })
const fileName = ref('')

function onFile(event: Event): void {
    const input = event.target as HTMLInputElement
    const file = input.files?.[0] ?? null

    form.file = file
    fileName.value = file?.name ?? ''
}

const badge: Record<string, string> = {
    selesai: 'bg-success-soft text-success',
    gagal: 'bg-danger-soft text-danger',
    dibatalkan: 'bg-surface-hover text-content-secondary',
    ditinjau: 'bg-warning-soft text-warning',
}
</script>

<template>
    <Head title="Impor Data Penduduk" />

    <AppLayout>
        <header class="mb-5">
            <h1 class="text-2xl font-semibold">Impor Data Penduduk</h1>
            <p class="mt-1 text-sm text-content-secondary">
                Unggah berkas Excel atau CSV untuk memasukkan banyak penduduk sekaligus.
            </p>
        </header>

        <AppAlert type="info" title="Berkas diperiksa dulu, belum langsung disimpan" class="mb-5">
            Setelah diunggah, seluruh baris diperiksa dan Anda akan melihat daftar
            baris bermasalah beserta sebabnya. Tidak ada data yang tersimpan sampai
            Anda menyetujuinya.
        </AppAlert>

        <div class="grid gap-5 lg:grid-cols-5">
            <!-- Unggah -->
            <section class="card p-5 lg:col-span-3">
                <h2 class="font-semibold">1. Unggah berkas</h2>

                <form class="mt-4 space-y-4"
                      @submit.prevent="form.post('/kependudukan/impor', { forceFormData: true })">
                    <div>
                        <label for="file" class="mb-1.5 block text-sm font-medium">
                            Berkas <span class="text-danger" aria-hidden="true">*</span>
                        </label>
                        <input
                            id="file"
                            type="file"
                            accept=".xlsx,.xls,.csv"
                            required
                            class="block w-full cursor-pointer rounded-lg border border-line-control
                                   bg-surface text-sm text-content-secondary
                                   file:mr-3 file:cursor-pointer file:border-0
                                   file:bg-surface-hover file:px-4 file:py-2.5
                                   file:text-sm file:font-medium file:text-content
                                   hover:border-content-tertiary focus:border-accent focus:outline-none"
                            :aria-describedby="form.errors.file ? 'file-error' : 'file-hint'"
                            @change="onFile"
                        >
                        <p v-if="form.errors.file" id="file-error" role="alert"
                           class="mt-1.5 text-sm font-medium text-danger">{{ form.errors.file }}</p>
                        <p v-else id="file-hint" class="mt-1.5 text-sm text-content-tertiary">
                            Format .xlsx atau .csv, maksimal 10 MB. Bagi menjadi beberapa
                            berkas bila lebih besar.
                        </p>
                    </div>

                    <AppButton type="submit" variant="primary"
                               :disabled="!form.file" :loading="form.processing">
                        <Upload class="size-4" aria-hidden="true" />
                        Unggah &amp; periksa
                    </AppButton>
                </form>
            </section>

            <!-- Template -->
            <section class="card p-5 lg:col-span-2">
                <h2 class="font-semibold">Template resmi</h2>
                <p class="mt-1.5 text-sm text-content-secondary">
                    Gunakan template ini agar kolomnya dikenali. Berkas sudah berisi
                    satu baris contoh.
                </p>
                <AppButton href="/kependudukan/impor/template" class="mt-4">
                    <Download class="size-4" aria-hidden="true" />
                    Unduh template
                </AppButton>

                <details class="mt-4">
                    <summary class="cursor-pointer text-sm font-medium text-accent">
                        Lihat daftar kolom
                    </summary>
                    <ul class="mt-3 space-y-1.5 text-sm">
                        <li v-for="(col, key) in columns" :key="key" class="flex gap-2">
                            <span class="font-medium">{{ col.judul }}</span>
                            <span v-if="col.wajib" class="text-danger" title="Wajib diisi">*</span>
                            <span v-if="col.keterangan" class="text-content-tertiary">
                                — {{ col.keterangan }}
                            </span>
                        </li>
                    </ul>
                </details>
            </section>
        </div>

        <!-- Riwayat -->
        <section class="mt-6">
            <h2 class="mb-3 font-semibold">Riwayat impor</h2>

            <EmptyState v-if="jobs.length === 0" :icon="FileSpreadsheet"
                        title="Belum ada impor"
                        description="Riwayat impor akan tampil di sini." />

            <ul v-else class="space-y-2">
                <li v-for="job in jobs" :key="job.id">
                    <Link :href="`/kependudukan/impor/${job.id}`"
                          class="card flex items-center gap-3 p-4 transition-colors hover:bg-surface-hover">
                        <FileSpreadsheet class="size-5 shrink-0 text-content-tertiary" aria-hidden="true" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium">{{ job.original_name }}</p>
                            <p class="text-sm text-content-tertiary">
                                {{ job.created_at }}
                                <template v-if="job.status === 'selesai'">
                                    · {{ job.created_count }} ditambah, {{ job.updated_count }} diperbarui
                                </template>
                            </p>
                        </div>
                        <span class="shrink-0 rounded-md px-2 py-0.5 text-xs font-medium"
                              :class="badge[job.status] ?? 'bg-info-soft text-info'">
                            {{ job.status_label }}
                        </span>
                        <ChevronRight class="size-4 shrink-0 text-content-tertiary" aria-hidden="true" />
                    </Link>
                </li>
            </ul>
        </section>
    </AppLayout>
</template>
