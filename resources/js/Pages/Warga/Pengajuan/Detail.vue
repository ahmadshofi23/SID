<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { ArrowLeft, Download, CircleCheck, Circle } from '@lucide/vue'
import WargaLayout from '@/Layouts/WargaLayout.vue'
import AppButton from '@/Components/AppButton.vue'
import AppAlert from '@/Components/AppAlert.vue'
import LetterStatusBadge from '@/Components/LetterStatusBadge.vue'
import type { LetterRequestForCitizen, LetterRequirement } from '@/types'

defineProps<{
    pengajuan: LetterRequestForCitizen
    syarat: LetterRequirement[]
    lampiran: { id: string; label: string | null; original_name: string; requirement_index: number | null }[]
}>()
</script>

<template>
    <Head :title="`Pengajuan ${pengajuan.ticket_number}`" />

    <WargaLayout>
        <Link href="/warga/pengajuan"
              class="mb-4 inline-flex items-center gap-1.5 text-sm text-content-secondary hover:text-content">
            <ArrowLeft class="size-4" aria-hidden="true" />
            Pengajuan saya
        </Link>

        <header class="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">{{ pengajuan.letter_type }}</h1>
                <p class="tabular mt-1 text-sm text-content-secondary">
                    Tiket {{ pengajuan.ticket_number }} · a.n. {{ pengajuan.resident_name }}
                </p>
            </div>
            <LetterStatusBadge :status="pengajuan.status" :label="pengajuan.status_label" />
        </header>

        <AppAlert v-if="pengajuan.rejection_reason" type="danger" class="mb-5"
                  title="Pengajuan ditolak">
            {{ pengajuan.rejection_reason }}
        </AppAlert>

        <AppAlert v-else-if="pengajuan.revision_note" type="warning" class="mb-5"
                  title="Petugas meminta perbaikan">
            <p>{{ pengajuan.revision_note }}</p>
            <p class="mt-2">
                Perbaikan dilakukan petugas di kantor desa. Bawa dokumen yang diminta
                pada jam layanan.
            </p>
        </AppAlert>

        <AppAlert v-else-if="pengajuan.is_overdue" type="warning" class="mb-5"
                  title="Melewati perkiraan selesai">
            Pengajuan ini sudah melewati perkiraan waktu penyelesaian. Anda dapat
            menanyakannya ke kantor desa dengan menyebut nomor tiket di atas.
        </AppAlert>

        <!-- ====================== Surat terbit ======================= -->
        <section v-if="pengajuan.letter_number" class="card mb-5 p-5" aria-labelledby="judul-surat">
            <h2 id="judul-surat" class="font-semibold">Surat sudah terbit</h2>
            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex justify-between gap-3">
                    <dt class="text-content-tertiary">Nomor surat</dt>
                    <dd class="tabular text-right font-medium">{{ pengajuan.letter_number }}</dd>
                </div>
                <div v-if="pengajuan.issued_at" class="flex justify-between gap-3">
                    <dt class="text-content-tertiary">Tanggal terbit</dt>
                    <dd class="text-right">{{ pengajuan.issued_at }}</dd>
                </div>
            </dl>

            <!--
              Unduhan memakai rute arsip yang sudah ada: LetterRepository
              berlingkup keluarga, jadi berkas keluarga lain tidak dapat
              diambil walau id-nya ditebak. Rute kedua hanya akan menjadi
              jalur yang harus ikut diperiksa setiap kali aturannya berubah.
            -->
            <AppButton v-if="pengajuan.letter_id" class="mt-4" variant="primary" block
                       :href="`/surat/arsip/${pengajuan.letter_id}/pdf`">
                <Download class="size-4" aria-hidden="true" />
                Unduh surat (PDF)
            </AppButton>

            <p v-else class="mt-4 text-sm text-content-secondary">
                Surat ini sudah dibatalkan, sehingga berkasnya tidak lagi dapat diunduh.
            </p>
        </section>

        <!-- ========================= Riwayat ========================= -->
        <section class="card mb-5 p-5" aria-labelledby="judul-riwayat">
            <h2 id="judul-riwayat" class="font-semibold">Riwayat</h2>

            <dl class="mt-3 space-y-2 border-b border-line pb-3 text-sm">
                <div class="flex justify-between gap-3">
                    <dt class="text-content-tertiary">Diajukan</dt>
                    <dd class="text-right">{{ pengajuan.submitted_at ?? '—' }}</dd>
                </div>
                <div v-if="pengajuan.is_open" class="flex justify-between gap-3">
                    <dt class="text-content-tertiary">Perkiraan selesai</dt>
                    <dd class="text-right" :class="pengajuan.is_overdue ? 'font-medium text-warning' : ''">
                        {{ pengajuan.due_at ?? '—' }}
                    </dd>
                </div>
                <div v-if="pengajuan.completed_at" class="flex justify-between gap-3">
                    <dt class="text-content-tertiary">Selesai</dt>
                    <dd class="text-right">{{ pengajuan.completed_at }}</dd>
                </div>
            </dl>

            <ol class="mt-4 space-y-3">
                <li v-for="langkah in pengajuan.timeline" :key="langkah.id" class="flex gap-3">
                    <CircleCheck class="mt-0.5 size-4 shrink-0 text-success" aria-hidden="true" />
                    <div class="min-w-0 text-sm">
                        <p class="font-medium">{{ langkah.to_label }}</p>
                        <p class="text-xs text-content-tertiary">{{ langkah.at }}</p>
                        <p v-if="langkah.note" class="mt-0.5 text-content-secondary">{{ langkah.note }}</p>
                    </div>
                </li>
                <li v-if="pengajuan.is_open" class="flex gap-3 opacity-60">
                    <Circle class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <p class="text-sm text-content-secondary">Menunggu tahap berikutnya</p>
                </li>
            </ol>
        </section>

        <!-- ======================== Lampiran ========================= -->
        <section v-if="syarat.length > 0" class="card p-5" aria-labelledby="judul-syarat">
            <h2 id="judul-syarat" class="font-semibold">Berkas syarat</h2>
            <p class="mt-1 text-xs text-content-tertiary">
                Berkas yang Anda unggah. Hanya petugas desa yang dapat membukanya.
            </p>

            <ul class="mt-3 space-y-2 text-sm">
                <li v-for="(req, i) in syarat" :key="i" class="flex flex-wrap items-center gap-2">
                    <CircleCheck v-if="lampiran.some((l) => l.requirement_index === i)"
                                 class="size-4 shrink-0 text-success" aria-hidden="true" />
                    <Circle v-else class="size-4 shrink-0 text-content-tertiary" aria-hidden="true" />
                    <span>{{ req.label }}</span>
                    <span v-if="!req.required" class="text-xs text-content-tertiary">(bila ada)</span>
                </li>
            </ul>
        </section>
    </WargaLayout>
</template>
