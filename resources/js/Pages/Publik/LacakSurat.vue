<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import { Landmark, CircleAlert, Clock, Search } from '@lucide/vue'
import TextField from '@/Components/TextField.vue'
import AppButton from '@/Components/AppButton.vue'
import AppAlert from '@/Components/AppAlert.vue'
import LetterStatusBadge from '@/Components/LetterStatusBadge.vue'
import { usePageData } from '@/composables/usePage'
import type { LetterRequestTracking } from '@/types'

const props = defineProps<{
    submitted: boolean
    found: boolean
    ticket_number?: string
    tracking?: LetterRequestTracking
}>()

const { village, user } = usePageData()

/*
 * Nomor tiket diisi ulang setelah pencarian gagal, tanggal lahir TIDAK.
 *
 * Mengisi ulang tanggal lahir berarti ia tersimpan di riwayat halaman pada
 * perangkat yang mungkin dipakai bersama — komputer balai desa, ponsel
 * pinjaman — sedangkan nomor tiket ada di tanda terima warga sendiri.
 */
const form = useForm({
    ticket_number: props.ticket_number ?? '',
    birth_date: '',
})
</script>

<template>
    <Head title="Lacak Status Pengajuan Surat" />

    <!--
      Halaman PUBLIK tanpa AppLayout, sama seperti verifikasi keaslian surat:
      pembukanya adalah warga yang tidak punya akun (akun warga baru ada di
      Sprint 6), dan menu panel admin di sini tidak ada gunanya bagi mereka.
    -->
    <div class="min-h-dvh bg-background px-4 py-10 text-content">
        <main class="mx-auto max-w-lg">
            <header class="mb-6 text-center">
                <span class="mx-auto mb-3 flex size-12 items-center justify-center rounded-xl bg-accent">
                    <Landmark class="size-6 text-content-inverse" aria-hidden="true" />
                </span>
                <h1 class="text-xl font-semibold">Lacak Status Pengajuan Surat</h1>
                <p class="mt-1 text-sm text-content-secondary">
                    Pemerintah Desa {{ village.name }}, Kec. {{ village.district }},
                    Kab. {{ village.regency }}
                </p>
            </header>

            <!-- ============ Formulir ============ -->
            <section class="card p-5" aria-label="Formulir pelacakan">
                <form class="space-y-4" @submit.prevent="form.post('/surat/lacak')">
                    <TextField
                        v-model="form.ticket_number"
                        label="Nomor tiket"
                        placeholder="T261015-C507E9"
                        required
                        mono
                        autofocus
                        hint="Tertera pada tanda terima dari kantor desa."
                        :error="form.errors.ticket_number"
                    />

                    <!--
                      Tanggal lahir adalah FAKTOR KEDUA, bukan data tambahan
                      yang diminta tanpa alasan: nomor tiket hanya memuat enam
                      karakter acak sehingga dapat dicoba-coba. Alasannya
                      dijelaskan ke warga agar tidak terasa seperti
                      pengumpulan data yang tak perlu.
                    -->
                    <TextField
                        v-model="form.birth_date"
                        type="date"
                        label="Tanggal lahir pemohon"
                        required
                        hint="Diminta agar status pengajuan Anda tidak dapat dibuka orang lain yang hanya menebak nomor tiket."
                        :error="form.errors.birth_date"
                    />

                    <AppButton type="submit" variant="primary" block :loading="form.processing">
                        <Search class="size-4" aria-hidden="true" />
                        Lacak pengajuan
                    </AppButton>
                </form>
            </section>

            <!-- ============ Tidak ditemukan ============ -->
            <!--
              Satu pesan untuk SEMUA kegagalan. Membedakan "tiket tidak ada"
              dari "tanggal lahir salah" akan mengubah halaman ini menjadi
              alat untuk memastikan sebuah nomor tiket memang terdaftar.
            -->
            <section
                v-if="submitted && !found"
                class="card mt-5 border-warning/40 p-5"
                aria-label="Hasil pelacakan"
            >
                <p class="mb-3 flex items-center gap-2 font-semibold text-warning">
                    <CircleAlert class="size-5 shrink-0" aria-hidden="true" />
                    Pengajuan tidak ditemukan
                </p>
                <p class="text-sm text-content-secondary">
                    Tidak ada pengajuan yang cocok dengan nomor tiket dan tanggal lahir
                    yang Anda masukkan.
                </p>
                <ul class="mt-3 space-y-1.5 text-sm text-content-secondary">
                    <li>· Periksa kembali nomor tiket pada tanda terima, termasuk tanda hubungnya.</li>
                    <li>· Tanggal lahir yang diisi harus milik <strong>pemohon surat</strong>, bukan milik pengantar.</li>
                    <li>· Bila keduanya sudah benar, hubungi kantor desa {{ village.name }}.</li>
                </ul>
            </section>

            <!-- ============ Hasil ============ -->
            <section
                v-else-if="submitted && found && tracking"
                class="card mt-5 p-5"
                aria-label="Hasil pelacakan"
            >
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="font-mono text-sm tabular tracking-wide">
                            {{ tracking.ticket_number }}
                        </p>
                        <p class="mt-0.5 text-sm text-content-secondary">
                            {{ tracking.letter_type }} · a.n. {{ tracking.resident_name }}
                        </p>
                    </div>
                    <LetterStatusBadge :status="tracking.status" :label="tracking.status_label" />
                </div>

                <dl class="mt-4 space-y-2 border-t border-line pt-4 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-content-tertiary">Diajukan</dt>
                        <dd class="text-right">{{ tracking.submitted_at ?? '—' }}</dd>
                    </div>
                    <div v-if="tracking.is_open" class="flex justify-between gap-3">
                        <dt class="text-content-tertiary">Estimasi selesai</dt>
                        <dd
                            class="text-right"
                            :class="tracking.is_overdue ? 'font-medium text-warning' : ''"
                        >
                            {{ tracking.due_at ?? '—' }}
                        </dd>
                    </div>
                    <div v-if="tracking.completed_at" class="flex justify-between gap-3">
                        <dt class="text-content-tertiary">Selesai</dt>
                        <dd class="text-right">{{ tracking.completed_at }}</dd>
                    </div>
                    <div v-if="tracking.letter_number" class="flex justify-between gap-3">
                        <dt class="text-content-tertiary">Nomor surat</dt>
                        <dd class="text-right font-medium tabular">{{ tracking.letter_number }}</dd>
                    </div>
                </dl>

                <!-- Melewati SLA: dikatakan apa adanya, bukan disembunyikan -->
                <AppAlert v-if="tracking.is_overdue" type="warning" class="mt-4">
                    <p class="font-medium">Pengajuan ini melewati estimasi selesai.</p>
                    <p class="mt-1">
                        Silakan hubungi kantor desa {{ village.name }} dengan menyebut nomor
                        tiket di atas.
                    </p>
                </AppAlert>

                <!-- BR-11 menyebut alasan penolakan secara eksplisit -->
                <AppAlert v-if="tracking.rejection_reason" type="danger" class="mt-4">
                    <p class="font-medium">Alasan penolakan</p>
                    <p class="mt-1">{{ tracking.rejection_reason }}</p>
                </AppAlert>

                <AppAlert v-if="tracking.revision_note" type="warning" class="mt-4">
                    <p class="font-medium">Yang perlu diperbaiki</p>
                    <p class="mt-1">{{ tracking.revision_note }}</p>
                </AppAlert>

                <!-- ====== Riwayat waktu tiap tahap (FR-SRT-13) ====== -->
                <section class="mt-5 border-t border-line pt-4" aria-label="Riwayat status">
                    <h2 class="mb-3 flex items-center gap-2 font-semibold">
                        <Clock class="size-4 text-content-tertiary" aria-hidden="true" />
                        Riwayat
                    </h2>
                    <ol class="space-y-3">
                        <li
                            v-for="step in tracking.timeline"
                            :key="step.id"
                            class="border-l-2 border-line pl-3 text-sm"
                        >
                            <p class="font-medium">{{ step.to_label }}</p>
                            <p class="text-xs text-content-tertiary">{{ step.at }}</p>
                            <p v-if="step.note" class="mt-1 text-content-secondary">
                                {{ step.note }}
                            </p>
                        </li>
                    </ol>
                    <p v-if="tracking.timeline.length === 0" class="text-sm text-content-secondary">
                        Belum ada perubahan status.
                    </p>
                </section>

                <!--
                  Dikatakan ke warga apa yang TIDAK ada di halaman ini, supaya
                  tidak ada yang menyangka halaman ini bisa dipakai menggali
                  data warga lain.
                -->
                <p class="mt-4 text-xs text-content-tertiary">
                    Nama ditampilkan sebagian untuk melindungi data pribadi. Halaman ini
                    tidak menampilkan NIK, alamat, maupun isi formulir pengajuan. Surat
                    yang sudah terbit diambil di kantor desa.
                </p>
            </section>

            <!--
              Petugas yang membuka halaman ini dari menu panel keluar dari
              AppLayout — tanpa tautan ini satu-satunya jalan kembali adalah
              tombol mundur peramban.
            -->
            <footer class="mt-6 text-center text-xs text-content-tertiary">
                <Link v-if="user" href="/dashboard" class="hover:text-content-secondary">
                    Kembali ke panel
                </Link>
                <Link v-else href="/" class="hover:text-content-secondary">
                    Beranda Desa {{ village.name }}
                </Link>
            </footer>
        </main>
    </div>
</template>
