<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import { Megaphone, CircleAlert, Clock, Search } from '@lucide/vue'
import TextField from '@/Components/TextField.vue'
import AppButton from '@/Components/AppButton.vue'
import AppAlert from '@/Components/AppAlert.vue'
import ComplaintStatusBadge from '@/Components/ComplaintStatusBadge.vue'
import { usePageData } from '@/composables/usePage'
import type { ComplaintTracking } from '@/types'

defineProps<{
    submitted: boolean
    found: boolean
    pengaduan?: ComplaintTracking
}>()

const { village, user } = usePageData()

/*
 * Kode TIDAK diisi ulang setelah pencarian gagal, berbeda dari nomor tiket
 * pada pelacakan surat.
 *
 * Nomor tiket ada di tanda terima warga sendiri dan mengisinya ulang hanya
 * menghemat pengetikan. Kode pelacakan adalah RAHASIA: mengisinya ulang
 * berarti ia tersimpan di riwayat halaman pada perangkat yang di desa sering
 * dipakai bergantian — ponsel pinjaman, komputer balai desa.
 */
const form = useForm({ tracking_code: '' })
</script>

<template>
    <Head title="Lacak Pengaduan" />

    <div class="min-h-dvh bg-background px-4 py-10 text-content">
        <main class="mx-auto max-w-lg">
            <header class="mb-6 text-center">
                <span class="mx-auto mb-3 flex size-12 items-center justify-center rounded-xl bg-accent">
                    <Megaphone class="size-6 text-content-inverse" aria-hidden="true" />
                </span>
                <h1 class="text-xl font-semibold">Lacak Pengaduan</h1>
                <p class="mt-1 text-sm text-content-secondary">
                    Pemerintah Desa {{ village.name }}, Kec. {{ village.district }},
                    Kab. {{ village.regency }}
                </p>
            </header>

            <section class="card p-5" aria-label="Formulir pelacakan pengaduan">
                <form class="space-y-4" @submit.prevent="form.post('/pengaduan/lacak')">
                    <TextField
                        v-model="form.tracking_code"
                        label="Kode pelacakan"
                        placeholder="XXXXXXXXXXXXXXXX"
                        required
                        mono
                        autofocus
                        hint="Enam belas karakter yang Anda terima setelah mengirim pengaduan."
                        :error="form.errors.tracking_code"
                    />

                    <AppButton type="submit" variant="primary" block :loading="form.processing">
                        <Search class="size-4" aria-hidden="true" />
                        Lacak pengaduan
                    </AppButton>
                </form>
            </section>

            <!--
              Satu pesan untuk SEMUA kegagalan, dan tanpa menyebut kembali kode
              yang dicoba: membedakan "kode tidak ada" dari kegagalan lain akan
              mengubah halaman ini menjadi alat untuk memastikan sebuah kode
              memang terdaftar.
            -->
            <section
                v-if="submitted && !found"
                class="card mt-5 border-warning/40 p-5"
                aria-label="Hasil pelacakan"
            >
                <p class="mb-3 flex items-center gap-2 font-semibold text-warning">
                    <CircleAlert class="size-5 shrink-0" aria-hidden="true" />
                    Pengaduan tidak ditemukan
                </p>
                <ul class="space-y-1.5 text-sm text-content-secondary">
                    <li>· Periksa kembali kodenya. Huruf besar-kecil tidak dipersoalkan.</li>
                    <!--
                      Kode tidak pernah memuat huruf I, L, O, U maupun angka 0 dan 1
                      (lihat ComplaintService::TRACKING_ALPHABET), justru supaya
                      pasangan yang mudah tertukar itu tidak pernah ada. Petunjuknya
                      dibalik: bila Anda membaca salah satunya, Anda salah baca.
                    -->
                    <li>
                        · Kode tidak pernah memuat angka 0 atau 1, maupun huruf I, L, O, dan U.
                        Bila Anda membaca salah satunya, kemungkinan itu angka 8, 2, J, atau V.
                    </li>
                    <li>
                        · Bila kode Anda hilang dan pengaduannya anonim, kode itu tidak dapat
                        dibuatkan ulang. Silakan sampaikan pengaduan baru, atau datang ke
                        kantor desa {{ village.name }} dengan menyebut nomor tiketnya.
                    </li>
                </ul>
            </section>

            <!-- ============ Hasil ============ -->
            <section
                v-else-if="submitted && found && pengaduan"
                class="card mt-5 p-5"
                aria-label="Hasil pelacakan"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-mono text-sm tabular tracking-wide">
                            {{ pengaduan.ticket_number }}
                        </p>
                        <p class="mt-0.5 font-medium">{{ pengaduan.title }}</p>
                        <p class="mt-0.5 text-sm text-content-secondary">{{ pengaduan.category }}</p>
                    </div>
                    <ComplaintStatusBadge
                        :status="pengaduan.status"
                        :label="pengaduan.status_label"
                    />
                </div>

                <dl class="mt-4 space-y-2 border-t border-line pt-4 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-content-tertiary">Dilaporkan</dt>
                        <dd class="text-right">{{ pengaduan.created_at ?? '—' }}</dd>
                    </div>
                    <div v-if="pengaduan.is_open" class="flex justify-between gap-3">
                        <dt class="text-content-tertiary">Batas penanganan</dt>
                        <dd
                            class="text-right"
                            :class="pengaduan.is_overdue ? 'font-medium text-warning' : ''"
                        >
                            {{ pengaduan.due_at ?? '—' }}
                        </dd>
                    </div>
                    <div v-if="pengaduan.resolved_at" class="flex justify-between gap-3">
                        <dt class="text-content-tertiary">Ditutup</dt>
                        <dd class="text-right">{{ pengaduan.resolved_at }}</dd>
                    </div>
                </dl>

                <!-- Melewati SLA: dikatakan apa adanya, bukan disembunyikan -->
                <AppAlert v-if="pengaduan.is_overdue" type="warning" class="mt-4">
                    <p class="font-medium">Pengaduan ini melewati batas waktu penanganan.</p>
                    <p class="mt-1">
                        Petugas sudah diberi peringatan otomatis. Bila tetap tidak ada
                        kabar, hubungi kantor desa {{ village.name }} dengan menyebut nomor
                        tiket di atas.
                    </p>
                </AppAlert>

                <AppAlert
                    v-if="pengaduan.resolution"
                    :type="pengaduan.status === 'ditolak' ? 'danger' : 'success'"
                    class="mt-4"
                >
                    <p class="font-medium">
                        {{ pengaduan.status === 'ditolak' ? 'Alasan' : 'Hasil penanganan' }}
                    </p>
                    <p class="mt-1">{{ pengaduan.resolution }}</p>
                </AppAlert>

                <!-- ====== Tindak lanjut yang dapat dibaca pelapor (FR-PGD-05) ====== -->
                <section class="mt-5 border-t border-line pt-4" aria-label="Riwayat tindak lanjut">
                    <h2 class="mb-3 flex items-center gap-2 font-semibold">
                        <Clock class="size-4 text-content-tertiary" aria-hidden="true" />
                        Tindak lanjut
                    </h2>
                    <ol class="space-y-3">
                        <li
                            v-for="step in pengaduan.timeline"
                            :key="step.id"
                            class="border-l-2 border-line pl-3 text-sm"
                        >
                            <p v-if="step.to_label" class="font-medium">{{ step.to_label }}</p>
                            <p class="text-xs text-content-tertiary">{{ step.at }}</p>
                            <p class="mt-1 text-content-secondary">{{ step.note }}</p>
                        </li>
                    </ol>
                    <p v-if="pengaduan.timeline.length === 0" class="text-sm text-content-secondary">
                        Belum ada tindak lanjut yang dicatat.
                    </p>
                </section>

                <!--
                  Dikatakan ke pelapor apa yang TIDAK ada di halaman ini: nama
                  petugas sengaja tidak disebut, karena pada pengaduan tentang
                  perangkat desa menyebutnya dapat membahayakan orang.
                -->
                <p class="mt-4 text-xs text-content-tertiary">
                    Halaman ini menampilkan status dan tindak lanjut yang ditujukan kepada
                    pelapor. Catatan koordinasi internal dan nama petugas yang menangani
                    tidak ditampilkan.
                </p>
            </section>

            <footer class="mt-6 space-y-2 text-center text-xs text-content-tertiary">
                <p>
                    Ada hal lain yang perlu dilaporkan?
                    <Link href="/pengaduan" class="text-accent hover:underline">
                        Sampaikan pengaduan
                    </Link>
                </p>
                <p>
                    <Link v-if="user" href="/dashboard" class="hover:text-content-secondary">
                        Kembali ke panel
                    </Link>
                    <Link v-else href="/" class="hover:text-content-secondary">
                        Beranda Desa {{ village.name }}
                    </Link>
                </p>
            </footer>
        </main>
    </div>
</template>
