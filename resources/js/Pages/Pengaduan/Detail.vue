<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import {
    ArrowLeft, Clock, ShieldOff, MapPin, Phone, EyeOff, Globe, TriangleAlert,
} from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/AppButton.vue'
import AppAlert from '@/Components/AppAlert.vue'
import SelectField from '@/Components/SelectField.vue'
import TextareaField from '@/Components/TextareaField.vue'
import ComplaintStatusBadge from '@/Components/ComplaintStatusBadge.vue'
import type { ComplaintDetail, ComplaintStatus, Option } from '@/types'

const props = defineProps<{
    pengaduan: ComplaintDetail
    statuses: Record<string, string>
    petugas: Option[]
    izin: { tindaklanjut: boolean; tugaskan: boolean }
}>()

const statusForm = useForm({ status: '' as ComplaintStatus | '', note: '' })
const assignForm = useForm({ assigned_to: props.pengaduan.assigned_to ?? '', note: '' })
const followUpForm = useForm({ note: '', is_visible_to_reporter: true })

const transisi = computed<Record<string, string>>(() =>
    Object.fromEntries(
        props.pengaduan.allowed_transitions.map((s) => [s, props.statuses[s] ?? s]),
    ),
)

/*
 * Catatan WAJIB pada dua transisi, dan layar mengatakannya sebelum tombol
 * ditekan — bukan setelah server menolak.
 *
 * "Selesai" tanpa narasi hasil meninggalkan pelapor yang hanya melihat kata
 * "Selesai" tanpa tahu apa yang dikerjakan; "Ditolak" tanpa alasan adalah
 * bentuk paling halus dari tidak melayani (FR-PGD-03, ditegakkan juga oleh
 * CHECK chk_complaints_tolak_beralasan).
 */
const catatanWajib = computed(() =>
    statusForm.status === 'selesai' || statusForm.status === 'ditolak')

const labelCatatan = computed(() =>
    statusForm.status === 'ditolak' ? 'Alasan penolakan' : 'Hasil penanganan')

function kirimStatus(): void {
    statusForm.post(`/pengaduan/antrean/${props.pengaduan.id}/status`, {
        preserveScroll: true,
        onSuccess: () => statusForm.reset(),
    })
}

function kirimPenugasan(): void {
    assignForm.post(`/pengaduan/antrean/${props.pengaduan.id}/tugaskan`, {
        preserveScroll: true,
    })
}

function kirimTindakLanjut(): void {
    followUpForm.post(`/pengaduan/antrean/${props.pengaduan.id}/tindak-lanjut`, {
        preserveScroll: true,
        onSuccess: () => followUpForm.reset(),
    })
}

const terbitForm = useForm({ is_published: false })

function ubahTerbit(nilai: boolean): void {
    terbitForm.is_published = nilai
    terbitForm.post(`/pengaduan/antrean/${props.pengaduan.id}/terbitkan`, {
        preserveScroll: true,
    })
}
</script>

<template>
    <Head :title="pengaduan.title" />

    <AppLayout :title="pengaduan.title">
        <Link href="/pengaduan/antrean"
              class="mb-4 inline-flex items-center gap-1.5 text-sm text-content-secondary hover:text-content">
            <ArrowLeft class="size-4" aria-hidden="true" />
            Antrean Pengaduan
        </Link>

        <header class="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-2xl font-semibold">{{ pengaduan.title }}</h1>
                <p class="tabular mt-1 text-sm text-content-tertiary">
                    {{ pengaduan.ticket_number }} · {{ pengaduan.category }}
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <span
                    v-if="pengaduan.priority === 'tinggi'"
                    class="rounded-md bg-danger-soft px-2 py-0.5 text-xs font-medium text-danger"
                >
                    Prioritas tinggi
                </span>
                <ComplaintStatusBadge :status="pengaduan.status" :label="pengaduan.status_label" />
            </div>
        </header>

        <AppAlert v-if="pengaduan.is_overdue" type="warning" class="mb-5">
            <p class="font-medium">{{ pengaduan.due_label }}</p>
            <p class="mt-1">
                Pelapor dapat melihat keterlambatan ini di halaman pelacakannya.
            </p>
        </AppAlert>

        <!--
          KEP-012: penolakan izin terlihat di antarmuka, dengan alasannya —
          bukan tombol yang diam-diam hilang. Perangkat desa yang tidak
          menemukan tombol akan mengira sistemnya rusak dan menelepon admin.
        -->
        <AppAlert v-if="!izin.tindaklanjut" type="info" class="mb-5">
            <p class="font-medium">Anda membuka pengaduan ini sebagai peninjau</p>
            <p class="mt-1">
                Peran Anda berwenang melihat pengaduan, tetapi tidak menindaklanjutinya
                (matriks hak akses). Tindak lanjut dikerjakan Kasi atau Admin Desa.
            </p>
        </AppAlert>

        <div class="grid gap-5 lg:grid-cols-3">
            <!-- ================= Isi & riwayat ================= -->
            <div class="space-y-5 lg:col-span-2">
                <section class="card p-5" aria-label="Isi pengaduan">
                    <p class="whitespace-pre-line text-sm">{{ pengaduan.body }}</p>

                    <dl class="mt-4 grid gap-3 border-t border-line pt-4 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-xs text-content-tertiary">Pelapor</dt>
                            <dd class="mt-0.5 inline-flex items-center gap-1.5">
                                <ShieldOff v-if="pengaduan.is_anonymous" class="size-4 text-content-tertiary"
                                           aria-hidden="true" />
                                {{ pengaduan.reporter_label }}
                            </dd>
                        </div>
                        <div v-if="pengaduan.reporter_phone">
                            <dt class="text-xs text-content-tertiary">Telepon</dt>
                            <dd class="mt-0.5 inline-flex items-center gap-1.5">
                                <Phone class="size-4 text-content-tertiary" aria-hidden="true" />
                                {{ pengaduan.reporter_phone }}
                            </dd>
                        </div>
                        <div v-if="pengaduan.region_path">
                            <dt class="text-xs text-content-tertiary">Wilayah</dt>
                            <dd class="mt-0.5 inline-flex items-center gap-1.5">
                                <MapPin class="size-4 text-content-tertiary" aria-hidden="true" />
                                {{ pengaduan.region_path }}
                            </dd>
                        </div>
                        <div v-if="pengaduan.location_text">
                            <dt class="text-xs text-content-tertiary">Keterangan lokasi</dt>
                            <dd class="mt-0.5">{{ pengaduan.location_text }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-content-tertiary">Masuk</dt>
                            <dd class="mt-0.5">{{ pengaduan.created_at ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-content-tertiary">Batas triase</dt>
                            <dd class="mt-0.5">{{ pengaduan.triage_due_at ?? '—' }}</dd>
                        </div>
                        <div v-if="pengaduan.due_at">
                            <dt class="text-xs text-content-tertiary">Batas penyelesaian</dt>
                            <dd class="mt-0.5">{{ pengaduan.due_at }}</dd>
                        </div>
                    </dl>

                    <!--
                      Pengingat anonim ditaruh DI DEKAT identitas pelapor, bukan
                      di catatan kaki: petugas yang hendak "menelepon pelapornya
                      saja" harus menemukan jawabannya di tempat ia mencarinya.
                    -->
                    <p v-if="pengaduan.is_anonymous"
                       class="mt-4 rounded-lg bg-surface-sunken p-3 text-xs text-content-secondary">
                        Pengaduan ini anonim. Sistem tidak menyimpan nama, nomor telepon,
                        maupun akun pelapornya, sehingga ia tidak dapat dihubungi —
                        tindak lanjut hanya dibacanya lewat halaman pelacakan.
                    </p>
                </section>

                <!-- ================= Riwayat ================= -->
                <section class="card p-5" aria-label="Riwayat tindak lanjut">
                    <h2 class="mb-3 flex items-center gap-2 font-semibold">
                        <Clock class="size-4 text-content-tertiary" aria-hidden="true" />
                        Riwayat
                    </h2>
                    <ol class="space-y-3">
                        <li v-for="step in pengaduan.timeline" :key="step.id"
                            class="border-l-2 pl-3 text-sm"
                            :class="step.is_visible_to_reporter ? 'border-line' : 'border-content-tertiary/40'">
                            <div class="flex flex-wrap items-center gap-2">
                                <span v-if="step.to_label" class="font-medium">{{ step.to_label }}</span>
                                <!--
                                  Catatan internal ditandai TERLIHAT, bukan hanya
                                  berbeda warna: petugas yang tidak tahu catatannya
                                  dibaca warga akan menuliskan dugaan yang belum
                                  terbukti di sana.
                                -->
                                <span
                                    v-if="!step.is_visible_to_reporter"
                                    class="inline-flex items-center gap-1 rounded bg-surface-hover px-1.5 py-0.5
                                           text-xs text-content-tertiary"
                                >
                                    <EyeOff class="size-3" aria-hidden="true" />
                                    Internal
                                </span>
                            </div>
                            <p class="text-xs text-content-tertiary">
                                {{ step.at }}<span v-if="step.actor_name"> · {{ step.actor_name }}</span>
                            </p>
                            <p class="mt-1 whitespace-pre-line text-content-secondary">{{ step.note }}</p>
                        </li>
                    </ol>
                </section>
            </div>

            <!-- ================= Tindakan ================= -->
            <div class="space-y-5">
                <section v-if="izin.tindaklanjut && pengaduan.allowed_transitions.length > 0"
                         class="card p-5" aria-label="Ubah status">
                    <h2 class="mb-3 font-semibold">Ubah status</h2>
                    <form class="space-y-4" @submit.prevent="kirimStatus">
                        <SelectField v-model="statusForm.status" label="Status baru"
                                     :options="transisi" required
                                     :error="statusForm.errors.status" />

                        <TextareaField
                            v-model="statusForm.note"
                            :label="catatanWajib ? labelCatatan : 'Catatan (opsional)'"
                            :required="catatanWajib"
                            :rows="4"
                            :maxlength="5000"
                            :hint="catatanWajib
                                ? 'Kalimat ini dibaca pelapor di halaman pelacakannya.'
                                : 'Dicatat di riwayat dan dibaca pelapor.'"
                            :error="statusForm.errors.note"
                        />

                        <AppButton type="submit" variant="primary" block
                                   :disabled="!statusForm.status" :loading="statusForm.processing">
                            Simpan status
                        </AppButton>
                    </form>
                </section>

                <section v-if="izin.tugaskan" class="card p-5" aria-label="Penugasan">
                    <h2 class="mb-3 font-semibold">Penugasan</h2>
                    <form class="space-y-4" @submit.prevent="kirimPenugasan">
                        <SelectField v-model="assignForm.assigned_to" label="Petugas"
                                     placeholder="— Belum ditugaskan —"
                                     :options="petugas"
                                     hint="Hanya peran yang berwenang menindaklanjuti yang dapat ditugaskan."
                                     :error="assignForm.errors.assigned_to" />
                        <TextareaField v-model="assignForm.note" label="Catatan untuk petugas (opsional)"
                                       :rows="2" :maxlength="1000"
                                       hint="Tidak dibaca pelapor."
                                       :error="assignForm.errors.note" />
                        <AppButton type="submit" block :loading="assignForm.processing">
                            Simpan penugasan
                        </AppButton>
                    </form>
                    <p v-if="pengaduan.assigned_at" class="mt-3 text-xs text-content-tertiary">
                        Ditugaskan {{ pengaduan.assigned_at }}
                    </p>
                </section>

                <section v-if="izin.tindaklanjut" class="card p-5" aria-label="Catat tindak lanjut">
                    <h2 class="mb-3 font-semibold">Catat tindak lanjut</h2>
                    <form class="space-y-4" @submit.prevent="kirimTindakLanjut">
                        <TextareaField v-model="followUpForm.note" label="Tindak lanjut"
                                       :rows="4" required :maxlength="5000"
                                       :error="followUpForm.errors.note" />

                        <label class="flex cursor-pointer items-start gap-3 text-sm">
                            <input
                                v-model="followUpForm.is_visible_to_reporter"
                                type="checkbox"
                                class="mt-0.5 size-4 shrink-0 rounded border-line-control text-accent
                                       focus:ring-2 focus:ring-accent"
                            />
                            <span class="min-w-0">
                                <span class="font-medium">Dibaca pelapor</span>
                                <span class="mt-0.5 block text-content-secondary">
                                    Hilangkan centang untuk koordinasi internal. Catatan internal
                                    tetap tercatat di riwayat, tetapi tidak muncul di halaman pelacakan.
                                </span>
                            </span>
                        </label>

                        <AppButton type="submit" block :loading="followUpForm.processing">
                            Catat
                        </AppButton>
                    </form>
                </section>

                <!-- ================= Publikasi (FR-PGD-07) ================= -->
                <section v-if="izin.tindaklanjut" class="card p-5" aria-label="Publikasi ke portal">
                    <h2 class="mb-2 flex items-center gap-2 font-semibold">
                        <Globe class="size-4 text-content-tertiary" aria-hidden="true" />
                        Ringkasan di portal desa
                    </h2>

                    <p v-if="pengaduan.status !== 'selesai'" class="text-sm text-content-secondary">
                        Hanya pengaduan yang sudah selesai dapat dipublikasikan.
                    </p>

                    <template v-else>
                        <!--
                          Apa yang terbit dikatakan SEBELUM tombolnya, bukan
                          sesudahnya: "jalan rusak di depan rumah Pak Sumadi"
                          yang terbit di portal desa menunjuk satu rumah
                          tertentu, dan itu bukan keterbukaan melainkan
                          penyingkapan.
                        -->
                        <p class="mb-3 text-sm text-content-secondary">
                            Yang terbit hanya kategori, judul, dan hasil penanganannya.
                            Lokasi, titik koordinat, dan pelapor tidak ikut.
                        </p>

                        <AppAlert v-if="!pengaduan.is_published" type="warning" class="mb-3">
                            <p class="flex items-center gap-1.5">
                                <TriangleAlert class="size-4 shrink-0" aria-hidden="true" />
                                Periksa judulnya lebih dulu — judul dapat menyebut nama orang.
                            </p>
                        </AppAlert>

                        <AppButton
                            :variant="pengaduan.is_published ? 'secondary' : 'primary'"
                            block
                            :loading="terbitForm.processing"
                            @click="ubahTerbit(!pengaduan.is_published)"
                        >
                            {{ pengaduan.is_published ? 'Tarik dari portal' : 'Terbitkan ringkasan' }}
                        </AppButton>
                    </template>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
