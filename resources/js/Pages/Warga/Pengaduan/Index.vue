<script setup lang="ts">
import { ref, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { Megaphone, ShieldOff } from '@lucide/vue'
import WargaLayout from '@/Layouts/WargaLayout.vue'
import AppButton from '@/Components/AppButton.vue'
import AppAlert from '@/Components/AppAlert.vue'
import SelectField from '@/Components/SelectField.vue'
import EmptyState from '@/Components/EmptyState.vue'
import DataPagination from '@/Components/DataPagination.vue'
import ComplaintStatusBadge from '@/Components/ComplaintStatusBadge.vue'
import type { CursorPage, ComplaintForCitizen } from '@/types'

const props = defineProps<{
    pengaduan: CursorPage<ComplaintForCitizen>
    filters: { status: string }
}>()

const status = ref(props.filters.status ?? '')

watch(status, () => {
    router.get('/warga/pengaduan', { status: status.value || undefined },
        { preserveState: true, preserveScroll: true, replace: true })
})

const statusOptions: Record<string, string> = {
    terbuka: 'Sedang ditangani',
    selesai: 'Selesai',
    ditolak: 'Ditolak',
}
</script>

<template>
    <Head title="Pengaduan Saya" />

    <WargaLayout>
        <header class="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">Pengaduan Saya</h1>
                <!--
                  Beda dengan "Pengajuan Saya" yang mencakup satu KK, dan
                  perbedaannya dikatakan: pengaduan tentang anggota keluarga
                  akan terbuka justru kepada orang yang diadukan bila
                  lingkupnya keluarga.
                -->
                <p class="mt-1 text-sm text-content-secondary">
                    Hanya pengaduan yang Anda laporkan sendiri.
                </p>
            </div>
            <AppButton href="/pengaduan" variant="primary">
                <Megaphone class="size-4" aria-hidden="true" />
                Adukan
            </AppButton>
        </header>

        <!--
          PERINGATAN YANG WAJIB ADA DI LAYAR INI.
          RULE-09 melarang menyimpan tautan apa pun antara pengaduan anonim dan
          akun pelapornya, sehingga pengaduan anonim TIDAK akan pernah muncul di
          daftar ini — bahkan milik orang yang sedang membukanya. Tanpa kalimat
          ini, warga yang melapor anonim lalu membuka halaman ini akan
          menyimpulkan laporannya hilang, dan berhenti memakai kanal pengaduan.
        -->
        <AppAlert type="info" class="mb-5">
            <p class="flex items-center gap-1.5 font-medium">
                <ShieldOff class="size-4 shrink-0" aria-hidden="true" />
                Pengaduan anonim tidak tampil di sini
            </p>
            <p class="mt-1">
                Pengaduan yang Anda kirim secara anonim memang tidak menyimpan identitas
                Anda, sehingga sistem tidak dapat menghubungkannya dengan akun ini.
                Pantau dengan kode pelacakan di
                <Link href="/pengaduan/lacak" class="text-accent hover:underline">
                    halaman pelacakan
                </Link>.
            </p>
        </AppAlert>

        <div class="card mb-5 p-4 sm:max-w-xs">
            <SelectField v-model="status" label="Tampilkan" placeholder="— Semua —"
                         :options="statusOptions" />
        </div>

        <EmptyState
            v-if="pengaduan.data.length === 0"
            :icon="Megaphone"
            title="Belum ada pengaduan"
            description="Pengaduan yang Anda sampaikan dengan menyertakan nama akan tampil di halaman ini."
        >
            <AppButton href="/pengaduan" variant="primary">
                <Megaphone class="size-4" aria-hidden="true" />
                Sampaikan Pengaduan
            </AppButton>
        </EmptyState>

        <ul v-else class="space-y-3">
            <li v-for="p in pengaduan.data" :key="p.id">
                <Link :href="`/warga/pengaduan/${p.id}`"
                      class="card block p-4 transition-colors hover:bg-surface-hover">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-medium">{{ p.title }}</p>
                            <p class="tabular mt-0.5 text-xs text-content-tertiary">
                                {{ p.ticket_number }} · {{ p.category }}
                            </p>
                        </div>
                        <ComplaintStatusBadge :status="p.status" :label="p.status_label" />
                    </div>

                    <p class="mt-2 text-xs text-content-secondary">
                        <span v-if="p.created_at">Dilaporkan {{ p.created_at }}</span>
                        <span v-if="p.location_text"> · {{ p.location_text }}</span>
                    </p>

                    <p v-if="p.is_overdue" class="mt-2 text-xs font-medium text-warning">
                        Melewati batas waktu penanganan.
                    </p>
                </Link>
            </li>
        </ul>

        <DataPagination
            v-if="pengaduan.data.length > 0"
            :prev-url="pengaduan.prev_url" :next-url="pengaduan.next_url"
            :count="pengaduan.count" noun="pengaduan"
        />
    </WargaLayout>
</template>
