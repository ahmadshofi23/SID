<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { ArrowLeft, Clock } from '@lucide/vue'
import WargaLayout from '@/Layouts/WargaLayout.vue'
import AppAlert from '@/Components/AppAlert.vue'
import ComplaintStatusBadge from '@/Components/ComplaintStatusBadge.vue'
import type { ComplaintForCitizen } from '@/types'

defineProps<{ pengaduan: ComplaintForCitizen }>()
</script>

<template>
    <Head :title="pengaduan.title" />

    <WargaLayout>
        <Link href="/warga/pengaduan"
              class="mb-4 inline-flex items-center gap-1.5 text-sm text-content-secondary hover:text-content">
            <ArrowLeft class="size-4" aria-hidden="true" />
            Pengaduan Saya
        </Link>

        <header class="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-2xl font-semibold">{{ pengaduan.title }}</h1>
                <p class="tabular mt-1 text-sm text-content-tertiary">
                    {{ pengaduan.ticket_number }} · {{ pengaduan.category }}
                </p>
            </div>
            <ComplaintStatusBadge :status="pengaduan.status" :label="pengaduan.status_label" />
        </header>

        <section class="card p-5" aria-label="Keterangan pengaduan">
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between gap-3">
                    <dt class="text-content-tertiary">Dilaporkan</dt>
                    <dd class="text-right">{{ pengaduan.created_at ?? '—' }}</dd>
                </div>
                <div v-if="pengaduan.location_text" class="flex justify-between gap-3">
                    <dt class="text-content-tertiary">Lokasi</dt>
                    <dd class="text-right">{{ pengaduan.location_text }}</dd>
                </div>
                <div v-if="pengaduan.is_open" class="flex justify-between gap-3">
                    <dt class="text-content-tertiary">Batas penanganan</dt>
                    <dd class="text-right"
                        :class="pengaduan.is_overdue ? 'font-medium text-warning' : ''">
                        {{ pengaduan.due_at ?? '—' }}
                    </dd>
                </div>
                <div v-if="pengaduan.resolved_at" class="flex justify-between gap-3">
                    <dt class="text-content-tertiary">Ditutup</dt>
                    <dd class="text-right">{{ pengaduan.resolved_at }}</dd>
                </div>
            </dl>
        </section>

        <AppAlert v-if="pengaduan.is_overdue" type="warning" class="mt-5">
            <p class="font-medium">Pengaduan ini melewati batas waktu penanganan.</p>
            <p class="mt-1">
                Petugas sudah diberi peringatan otomatis. Bila tetap tidak ada kabar,
                hubungi kantor desa dengan menyebut nomor tiket di atas.
            </p>
        </AppAlert>

        <AppAlert
            v-if="pengaduan.resolution"
            :type="pengaduan.status === 'ditolak' ? 'danger' : 'success'"
            class="mt-5"
        >
            <p class="font-medium">
                {{ pengaduan.status === 'ditolak' ? 'Alasan' : 'Hasil penanganan' }}
            </p>
            <p class="mt-1">{{ pengaduan.resolution }}</p>
        </AppAlert>

        <section class="card mt-5 p-5" aria-label="Riwayat tindak lanjut">
            <h2 class="mb-3 flex items-center gap-2 font-semibold">
                <Clock class="size-4 text-content-tertiary" aria-hidden="true" />
                Tindak lanjut
            </h2>
            <ol class="space-y-3">
                <li v-for="step in pengaduan.timeline" :key="step.id"
                    class="border-l-2 border-line pl-3 text-sm">
                    <p v-if="step.to_label" class="font-medium">{{ step.to_label }}</p>
                    <p class="text-xs text-content-tertiary">{{ step.at }}</p>
                    <p class="mt-1 text-content-secondary">{{ step.note }}</p>
                </li>
            </ol>
            <p v-if="pengaduan.timeline.length === 0" class="text-sm text-content-secondary">
                Belum ada tindak lanjut yang dicatat.
            </p>

            <!--
              Nama petugas sengaja tidak disebut, sama seperti pada halaman
              pelacakan publik: pada pengaduan tentang perangkat desa,
              menyebutnya dapat membahayakan orang.
            -->
            <p class="mt-4 text-xs text-content-tertiary">
                Catatan koordinasi internal dan nama petugas yang menangani tidak
                ditampilkan di halaman ini.
            </p>
        </section>
    </WargaLayout>
</template>
