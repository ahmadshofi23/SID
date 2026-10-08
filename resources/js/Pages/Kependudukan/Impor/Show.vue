<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { ChevronRight, Play, X, CircleCheck, TriangleAlert, LoaderCircle } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/AppButton.vue'
import AppAlert from '@/Components/AppAlert.vue'

interface RowError { baris: number; kolom: string; pesan: string }

interface Job {
    id: string
    original_name: string
    status: string
    status_label: string
    total_rows: number
    valid_rows: number
    invalid_rows: number
    processed_rows: number
    created_count: number
    updated_count: number
    skipped_count: number
    progress: number
    failure_reason: string | null
    is_finished: boolean
    created_at: string
    errors: RowError[] | null
}

const props = defineProps<{ job: Job }>()

const job = ref<Job>(props.job)
const confirmForm = useForm({})
const cancelForm = useForm({})

let timer: ReturnType<typeof setInterval> | undefined

/*
 * Polling status — UC-04 langkah 3.
 *
 * Interval 2 detik dan BERHENTI begitu pekerjaan selesai. Polling yang terus
 * berjalan membebani server desa tanpa guna; yang berhenti terlalu cepat
 * membuat petugas mengira sistemnya menggantung.
 */
function poll(): void {
    if (job.value.is_finished || job.value.status === 'ditinjau') return

    fetch(`/kependudukan/impor/${job.value.id}/status`, {
        headers: { Accept: 'application/json' },
    })
        .then((r) => r.json())
        .then((data: Job) => {
            const wasProcessing = !job.value.is_finished
            job.value = data

            // Saat selesai, muat ulang halaman agar pesan & riwayat ikut segar.
            if (data.is_finished && wasProcessing) {
                router.reload({ only: ['job'] })
            }
        })
        .catch(() => { /* jaringan desa kadang putus — coba lagi siklus berikutnya */ })
}

onMounted(() => { timer = setInterval(poll, 2000) })
onUnmounted(() => clearInterval(timer))
</script>

<template>
    <Head title="Hasil Impor" />

    <AppLayout>
        <nav class="mb-4 flex items-center gap-1 text-sm text-content-tertiary" aria-label="Remah roti">
            <Link href="/kependudukan/impor" class="hover:text-content-secondary hover:underline">
                Impor Data
            </Link>
            <ChevronRight class="size-3.5" aria-hidden="true" />
            <span class="truncate text-content-secondary">{{ job.original_name }}</span>
        </nav>

        <header class="mb-5 flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <h1 class="truncate text-2xl font-semibold">{{ job.original_name }}</h1>
                <p class="mt-1 text-sm text-content-secondary">
                    Diunggah {{ job.created_at }} · {{ job.status_label }}
                </p>
            </div>

            <div v-if="!job.is_finished" class="flex flex-wrap gap-2">
                <AppButton
                    v-if="job.status === 'ditinjau' && job.valid_rows > 0"
                    variant="primary"
                    :loading="confirmForm.processing"
                    @click="confirmForm.post(`/kependudukan/impor/${job.id}/jalankan`)"
                >
                    <Play class="size-4" aria-hidden="true" />
                    Jalankan impor ({{ job.valid_rows }} baris)
                </AppButton>

                <AppButton
                    variant="secondary"
                    :loading="cancelForm.processing"
                    @click="cancelForm.post(`/kependudukan/impor/${job.id}/batal`)"
                >
                    <X class="size-4" aria-hidden="true" />
                    Batalkan
                </AppButton>
            </div>
        </header>

        <AppAlert v-if="job.status === 'gagal'" type="danger" title="Impor gagal" class="mb-5">
            {{ job.failure_reason ?? 'Penyebabnya tidak diketahui.' }}
        </AppAlert>

        <!-- Sedang berjalan -->
        <section v-if="['menunggu', 'pra_validasi', 'diproses'].includes(job.status)"
                 class="card mb-5 p-5">
            <div class="flex items-center gap-3">
                <LoaderCircle class="size-5 animate-spin text-accent" aria-hidden="true" />
                <p class="font-medium">{{ job.status_label }}…</p>
            </div>

            <div v-if="job.status === 'diproses'" class="mt-4">
                <div class="h-2 overflow-hidden rounded-full bg-surface-hover">
                    <div class="h-full rounded-full bg-accent transition-[width] duration-500"
                         :style="{ width: `${job.progress}%` }"
                         role="progressbar"
                         :aria-valuenow="job.progress"
                         aria-valuemin="0" aria-valuemax="100"
                         aria-label="Kemajuan impor" />
                </div>
                <p class="mt-2 text-sm text-content-tertiary tabular">
                    {{ job.processed_rows }} dari {{ job.total_rows }} baris ({{ job.progress }}%)
                </p>
            </div>

            <p class="mt-3 text-sm text-content-tertiary">
                Halaman ini memperbarui diri sendiri. Anda boleh menutupnya —
                prosesnya tetap berjalan.
            </p>
        </section>

        <!-- Ringkasan angka -->
        <div v-if="job.total_rows > 0" class="mb-5 grid gap-4 sm:grid-cols-3">
            <div class="card p-4">
                <p class="text-sm text-content-secondary">Total baris</p>
                <p class="mt-1 text-2xl font-semibold tabular">{{ job.total_rows }}</p>
            </div>
            <div class="card p-4">
                <p class="text-sm text-content-secondary">Baris sah</p>
                <p class="mt-1 text-2xl font-semibold tabular text-success">{{ job.valid_rows }}</p>
            </div>
            <div class="card p-4">
                <p class="text-sm text-content-secondary">Baris bermasalah</p>
                <p class="mt-1 text-2xl font-semibold tabular"
                   :class="job.invalid_rows > 0 ? 'text-danger' : 'text-content-tertiary'">
                    {{ job.invalid_rows }}
                </p>
            </div>
        </div>

        <!-- Selesai -->
        <AppAlert v-if="job.status === 'selesai'" type="success" title="Impor selesai" class="mb-5">
            <ul class="mt-1 space-y-0.5">
                <li>{{ job.created_count }} penduduk ditambahkan</li>
                <li>{{ job.updated_count }} penduduk diperbarui (NIK sudah ada sebelumnya)</li>
                <li v-if="job.skipped_count > 0">{{ job.skipped_count }} baris dilewati karena bermasalah</li>
            </ul>
            <p class="mt-2">
                <Link href="/kependudukan/penduduk" class="font-medium underline">
                    Lihat data penduduk
                </Link>
            </p>
        </AppAlert>

        <!-- Menunggu persetujuan -->
        <AppAlert v-if="job.status === 'ditinjau'" type="warning"
                  title="Belum ada data yang tersimpan" class="mb-5">
            <template v-if="job.invalid_rows === 0">
                Seluruh {{ job.valid_rows }} baris lolos pemeriksaan. Tekan
                <strong>Jalankan impor</strong> untuk menyimpannya.
            </template>
            <template v-else>
                {{ job.valid_rows }} baris lolos dan {{ job.invalid_rows }} bermasalah.
                Bila Anda melanjutkan, <strong>hanya baris yang lolos</strong> yang disimpan;
                sisanya dilewati. Perbaiki berkasnya lalu unggah ulang bila ingin semuanya masuk.
            </template>
        </AppAlert>

        <!-- Daftar galat -->
        <section v-if="job.errors && job.errors.length > 0" class="card p-5">
            <div class="mb-3 flex items-center gap-2">
                <TriangleAlert class="size-4 text-warning" aria-hidden="true" />
                <h2 class="font-semibold">Baris bermasalah</h2>
            </div>

            <div class="overflow-hidden rounded-lg border border-line">
                <table class="w-full text-sm">
                    <caption class="sr-only">Daftar baris yang gagal pemeriksaan</caption>
                    <thead>
                        <tr class="border-b border-line bg-surface-sunken text-left">
                            <th scope="col" class="px-3 py-2 font-semibold">Baris</th>
                            <th scope="col" class="px-3 py-2 font-semibold">Kolom</th>
                            <th scope="col" class="px-3 py-2 font-semibold">Masalah</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(err, i) in job.errors.slice(0, 100)" :key="i"
                            class="border-b border-line last:border-0">
                            <td class="px-3 py-2 tabular">{{ err.baris }}</td>
                            <td class="px-3 py-2 text-content-secondary">{{ err.kolom }}</td>
                            <td class="px-3 py-2">{{ err.pesan }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p v-if="job.errors.length > 100" class="mt-3 text-sm text-content-tertiary">
                Menampilkan 100 dari {{ job.errors.length }} masalah.
            </p>
        </section>

        <p v-else-if="job.status === 'ditinjau'"
           class="flex items-center gap-2 text-sm text-success">
            <CircleCheck class="size-4" aria-hidden="true" />
            Tidak ada baris bermasalah.
        </p>
    </AppLayout>
</template>
