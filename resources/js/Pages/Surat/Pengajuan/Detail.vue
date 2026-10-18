<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import {
    ArrowLeft, TriangleAlert, Stamp, CircleCheck, Undo2, CircleX,
    Printer, Hash, ClipboardList, MapPinned,
} from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/AppButton.vue'
import AppAlert from '@/Components/AppAlert.vue'
import SelectField from '@/Components/SelectField.vue'
import TextareaField from '@/Components/TextareaField.vue'
import LetterStatusBadge from '@/Components/LetterStatusBadge.vue'
import LampiranPanel from '@/Components/LampiranPanel.vue'
import TawaranMutasiPanel from '@/Components/TawaranMutasiPanel.vue'
import { usePageData } from '@/composables/usePage'
import type {
    LetterRequestDetail, LetterFormField, LetterAttachment, UploadConstraints,
    LetterMutationOffer,
} from '@/types'

const props = defineProps<{
    request: LetterRequestDetail
    form_fields: LetterFormField[]
    attachments: LetterAttachment[]
    upload: UploadConstraints
    next_number_preview: string | null
    signers: { id: string; name: string; position: string }[]
    /** Tawaran mutasi SKL/SKM — null untuk jenis surat lain (docs/13 §5.4-5.5). */
    mutation_offer: LetterMutationOffer | null
}>()

const { can, user } = usePageData()

/*
 * Konfirmasi lapangan & peringatan WAJIB dicentang sebelum tombol terbit
 * aktif (docs/13 §6).
 *
 * Ini satu-satunya tempat di seluruh sistem yang memakai centang pengakuan.
 * Alasannya: SKAW, SKPT, dan SKPH berimplikasi hukum atau keuangan, dan
 * kesalahannya TIDAK dapat diperbaiki dengan membatalkan surat — sengketa
 * tanah dan gagal bayar kredit tetap berjalan.
 */
const warningAcknowledged = ref(false)
const fieldCheckAcknowledged = ref(false)

/*
 * Lampiran dapat diubah hanya SEBELUM surat terbit (ADR-003).
 *
 * Setelah surat keluar, berkas syarat yang mendasarinya ikut menjadi arsip:
 * tanpa itu, pertanyaan "atas dasar apa surat ini terbit?" tidak lagi dapat
 * dijawab saat pemeriksaan. Penegakannya ada di LetterAttachmentService;
 * di sini tombolnya hanya disembunyikan agar tidak menjanjikan yang mustahil.
 */
const canManageAttachments = computed(() =>
    can('surat.ubah') && props.request.status !== 'diterbitkan')

const needsWarningAck = computed(() => props.request.warning_note !== null)
const needsFieldCheckAck = computed(() => props.request.requires_field_check)

/*
 * Apakah pengguna ini pemeriksa tahap SAAT INI?
 *
 * Admin Desa selalu boleh (lihat guardStepAuthority di server). Tanpa
 * pemeriksaan ini, tombol Verifikasi tampil bagi setiap pemegang izin
 * `surat.verifikasi` — termasuk Kasi bidang lain — lalu menghasilkan
 * penolakan 403 yang membingungkan.
 */
const isStepReviewer = computed(() =>
    (user.value?.roles ?? []).includes('admin_desa')
    || props.request.step_roles.some((role) => (user.value?.roles ?? []).includes(role)))

const canVerifyNow = computed(() =>
    can('surat.verifikasi')
    && isStepReviewer.value
    && (props.request.allowed_transitions.includes('diverifikasi')
        || props.request.status === 'diajukan'))

const canIssueNow = computed(() =>
    props.request.allowed_transitions.includes('diterbitkan')
    && can('surat.setujui')
    && (!needsWarningAck.value || warningAcknowledged.value)
    && (!needsFieldCheckAck.value || fieldCheckAcknowledged.value))

const issueForm = useForm({ signer_official_id: '', note: '' })
const revisionForm = useForm({ note: '' })
const rejectForm = useForm({ reason: '' })

const openPanel = ref<'revisi' | 'tolak' | null>(null)

const signerOptions = computed(() => [
    { value: '', label: 'Penanda tangan bawaan (Kepala Desa)' },
    ...props.signers.map((s) => ({ value: s.id, label: `${s.name} — ${s.position}` })),
])

function verify(): void {
    router.post(`/surat/${props.request.id}/verifikasi`, {}, { preserveScroll: true })
}

function issue(): void {
    issueForm.post(`/surat/${props.request.id}/terbitkan`, { preserveScroll: true })
}

function requestRevision(): void {
    revisionForm.post(`/surat/${props.request.id}/revisi`, {
        preserveScroll: true,
        onSuccess: () => { openPanel.value = null; revisionForm.reset() },
    })
}

function reject(): void {
    rejectForm.post(`/surat/${props.request.id}/tolak`, {
        preserveScroll: true,
        onSuccess: () => { openPanel.value = null; rejectForm.reset() },
    })
}

/** Nilai isian dinamis sebagai teks yang terbaca. */
function fieldValue(field: LetterFormField): string {
    const raw = props.request.form_data[field.name]

    if (raw === null || raw === undefined || raw === '') return '—'
    if (Array.isArray(raw)) return raw.join(', ')
    if (typeof raw === 'boolean') return raw ? 'Ya' : 'Tidak'

    if (field.type === 'currency') {
        return new Intl.NumberFormat('id-ID', {
            style: 'currency', currency: 'IDR', maximumFractionDigits: 0,
        }).format(Number(raw))
    }

    return String(raw)
}

function formatDateTime(iso: string | null): string {
    if (!iso) return '—'

    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'long', timeStyle: 'short',
    }).format(new Date(iso))
}

/*
 * Tanggal saja (tanpa jam). `valid_until` berupa "2027-01-15"; menampilkan
 * bentuk mentah itu pada layar yang dibaca petugas desa membuat sistem
 * terasa belum jadi, dan mudah salah baca sebagai 1 Mei.
 */
function formatDate(value: string | null): string {
    if (!value) return 'Tanpa batas'

    return new Intl.DateTimeFormat('id-ID', { dateStyle: 'long' })
        .format(new Date(`${value}T00:00:00`))
}
</script>

<template>
    <Head :title="`Pengajuan ${request.ticket_number}`" />

    <AppLayout>
        <Link
            href="/surat"
            class="mb-4 inline-flex items-center gap-1.5 text-sm text-content-secondary hover:text-content"
        >
            <ArrowLeft class="size-4" aria-hidden="true" />
            Kembali ke antrean
        </Link>

        <header class="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-sm tabular text-content-tertiary">{{ request.ticket_number }}</p>
                <h1 class="text-2xl font-semibold">
                    {{ request.letter_type.name }}
                </h1>
                <p class="mt-1 text-sm text-content-secondary">
                    Pemohon: {{ request.resident.full_name }}
                    <span class="tabular">({{ request.resident.nik_masked }})</span>
                </p>
            </div>

            <div class="flex flex-col items-end gap-2">
                <LetterStatusBadge :status="request.status" :label="request.status_label" />
                <p
                    v-if="request.is_overdue"
                    class="inline-flex items-center gap-1 text-xs font-medium text-danger"
                >
                    <TriangleAlert class="size-3.5" aria-hidden="true" />
                    Melewati batas waktu
                </p>
            </div>
        </header>

        <!-- Peringatan jenis surat berisiko: SEBELUM isi, bukan di dekat tombol -->
        <AppAlert v-if="request.warning_note" type="warning" class="mb-5">
            <p class="font-medium">Perhatian sebelum menyetujui</p>
            <p class="mt-1">{{ request.warning_note }}</p>
        </AppAlert>

        <div class="grid gap-5 lg:grid-cols-3">
            <!-- ============ Kolom utama ============ -->
            <div class="space-y-5 lg:col-span-2">

                <!-- Surat terbit -->
                <section v-if="request.letter" class="card p-4" aria-label="Surat terbit">
                    <h2 class="mb-3 flex items-center gap-2 font-semibold">
                        <Stamp class="size-4 text-success" aria-hidden="true" />
                        Surat sudah terbit
                    </h2>

                    <dl class="grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-content-tertiary">Nomor surat</dt>
                            <dd class="font-medium tabular">{{ request.letter.letter_number }}</dd>
                        </div>
                        <div>
                            <dt class="text-content-tertiary">Tanggal terbit</dt>
                            <dd>{{ formatDateTime(request.letter.issued_at) }}</dd>
                        </div>
                        <div>
                            <dt class="text-content-tertiary">Penanda tangan</dt>
                            <dd>{{ request.letter.signer_name }} — {{ request.letter.signer_position }}</dd>
                        </div>
                        <div>
                            <dt class="text-content-tertiary">Masa berlaku</dt>
                            <dd :class="request.letter.is_expired ? 'text-danger' : ''">
                                {{ formatDate(request.letter.valid_until) }}
                                <span v-if="request.letter.is_expired">(habis)</span>
                            </dd>
                        </div>
                    </dl>

                    <AppAlert
                        v-if="request.letter.status === 'dibatalkan'"
                        type="danger"
                        class="mt-3"
                    >
                        Surat ini DIBATALKAN. Alasan: {{ request.letter.cancel_reason }}
                    </AppAlert>

                    <AppButton
                        :href="`/surat/arsip/${request.letter.id}/cetak`"
                        variant="primary"
                        class="mt-4"
                    >
                        <Printer class="size-4" aria-hidden="true" />
                        Lihat &amp; cetak surat
                    </AppButton>
                </section>

                <!--
                  Tawaran mutasi SKL/SKM — docs/13 §5.4 & §5.5, KEP-015.

                  Di kolom utama, tepat setelah "Surat sudah terbit": ia
                  menyangkut data penduduk, bukan alur surat. Ditaruh di
                  panel tindakan, ia akan terbaca sebagai langkah lanjutan
                  yang wajib — padahal menolaknya sah dan tidak berakibat
                  apa pun pada suratnya.
                -->
                <TawaranMutasiPanel
                    v-if="mutation_offer"
                    :request-id="request.id"
                    :offer="mutation_offer"
                    :can-decide="can('mutasi.tambah')"
                    :can-add-resident="can('penduduk.tambah')"
                />

                <!-- Isian formulir -->
                <section class="card p-4" aria-label="Isian formulir">
                    <h2 class="mb-3 flex items-center gap-2 font-semibold">
                        <ClipboardList class="size-4 text-content-tertiary" aria-hidden="true" />
                        Isian pemohon
                    </h2>

                    <dl v-if="form_fields.length > 0" class="grid gap-3 text-sm sm:grid-cols-2">
                        <div v-if="request.purpose" class="sm:col-span-2">
                            <dt class="text-content-tertiary">Keperluan</dt>
                            <dd>{{ request.purpose }}</dd>
                        </div>
                        <div
                            v-for="field in form_fields"
                            :key="field.name"
                            :class="field.type === 'textarea' ? 'sm:col-span-2' : ''"
                        >
                            <dt class="text-content-tertiary">{{ field.label }}</dt>
                            <dd class="whitespace-pre-line">{{ fieldValue(field) }}</dd>
                        </div>
                    </dl>

                    <p v-else class="text-sm text-content-tertiary">
                        Jenis surat ini tidak memiliki isian tambahan.
                    </p>
                </section>

                <!-- Syarat berkas & lampirannya -->
                <LampiranPanel
                    v-if="request.requirements.length > 0 || attachments.length > 0"
                    :request-id="request.id"
                    :requirements="request.requirements"
                    :attachments="attachments"
                    :upload="upload"
                    :editable="canManageAttachments"
                />

                <!-- Riwayat -->
                <section class="card p-4" aria-label="Riwayat status">
                    <h2 class="mb-3 font-semibold">Riwayat</h2>
                    <ol class="space-y-3">
                        <li
                            v-for="log in request.logs"
                            :key="log.id"
                            class="border-l-2 border-line pl-3 text-sm"
                        >
                            <p class="font-medium">
                                {{ log.from_label ? `${log.from_label} → ` : '' }}{{ log.to_label }}
                            </p>
                            <p class="text-xs text-content-tertiary">
                                {{ log.actor_name ?? 'sistem' }} ·
                                {{ formatDateTime(log.created_at) }}
                            </p>
                            <p v-if="log.note" class="mt-1 text-content-secondary">{{ log.note }}</p>
                        </li>
                    </ol>
                </section>
            </div>

            <!-- ============ Panel tindakan ============ -->
            <aside class="space-y-5">
                <section class="card p-4" aria-label="Tindakan">
                    <h2 class="mb-3 font-semibold">Tindakan</h2>

                    <p
                        v-if="request.status === 'diajukan' && request.step_total > 1"
                        class="mb-3 text-sm text-content-secondary"
                    >
                        Tahap pemeriksaan {{ request.current_step + 1 }} dari {{ request.step_total }}.
                    </p>

                    <!-- Verifikasi -->
                    <AppButton
                        v-if="canVerifyNow"
                        variant="primary"
                        block
                        class="mb-2"
                        @click="verify"
                    >
                        <CircleCheck class="size-4" aria-hidden="true" />
                        Verifikasi
                    </AppButton>

                    <!--
                      Penjelasan, bukan tombol mati: petugas perlu tahu SIAPA
                      yang harus memeriksa, agar ia dapat meneruskan berkasnya
                      alih-alih menunggu tanpa kejelasan.
                    -->
                    <AppAlert
                        v-else-if="request.status === 'diajukan' && can('surat.verifikasi')"
                        type="info"
                        class="mb-2"
                    >
                        Tahap pemeriksaan ini kewenangan
                        <strong>{{ request.step_role_labels.join(' atau ') }}</strong>.
                    </AppAlert>

                    <!-- Penerbitan -->
                    <div v-if="request.allowed_transitions.includes('diterbitkan') && can('surat.setujui')">
                        <AppAlert v-if="next_number_preview" type="info" class="mb-3">
                            <p class="flex items-center gap-1.5">
                                <Hash class="size-3.5" aria-hidden="true" />
                                Nomor berikutnya:
                                <span class="font-medium tabular">{{ next_number_preview }}</span>
                            </p>
                            <p class="mt-1 text-xs">
                                Perkiraan. Nomor sebenarnya ditetapkan saat surat terbit.
                            </p>
                        </AppAlert>

                        <SelectField
                            v-model="issueForm.signer_official_id"
                            label="Penanda tangan"
                            :options="signerOptions"
                            :error="issueForm.errors.signer_official_id"
                            class="mb-3"
                        />

                        <label
                            v-if="needsFieldCheckAck"
                            class="mb-3 flex items-start gap-2 rounded-lg bg-warning-soft p-3 text-sm"
                        >
                            <input
                                v-model="fieldCheckAcknowledged"
                                type="checkbox"
                                class="mt-0.5 size-4 shrink-0 rounded border-line-control"
                            >
                            <span class="flex items-start gap-1.5">
                                <MapPinned class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                Pengecekan lapangan sudah dilakukan dan saksi batas hadir.
                            </span>
                        </label>

                        <label
                            v-if="needsWarningAck"
                            class="mb-3 flex items-start gap-2 rounded-lg bg-warning-soft p-3 text-sm"
                        >
                            <input
                                v-model="warningAcknowledged"
                                type="checkbox"
                                class="mt-0.5 size-4 shrink-0 rounded border-line-control"
                            >
                            Saya sudah membaca peringatan di atas dan memeriksa berkasnya.
                        </label>

                        <AppButton
                            variant="primary"
                            block
                            class="mb-2"
                            :disabled="!canIssueNow"
                            :loading="issueForm.processing"
                            @click="issue"
                        >
                            <Stamp class="size-4" aria-hidden="true" />
                            Setujui &amp; terbitkan
                        </AppButton>
                    </div>

                    <!-- Revisi & penolakan: kewenangan pemeriksa tahap ini -->
                    <template v-if="can('surat.verifikasi') && isStepReviewer">
                        <AppButton
                            v-if="request.allowed_transitions.includes('perlu_revisi')"
                            block
                            class="mb-2"
                            @click="openPanel = openPanel === 'revisi' ? null : 'revisi'"
                        >
                            <Undo2 class="size-4" aria-hidden="true" />
                            Minta revisi
                        </AppButton>

                        <div v-if="openPanel === 'revisi'" class="mb-3">
                            <TextareaField
                                v-model="revisionForm.note"
                                label="Apa yang harus diperbaiki?"
                                required
                                :maxlength="1000"
                                :error="revisionForm.errors.note"
                                placeholder="Contoh: surat pengantar RT belum ada tanda tangan."
                            />
                            <AppButton
                                variant="primary"
                                size="sm"
                                block
                                class="mt-2"
                                :loading="revisionForm.processing"
                                @click="requestRevision"
                            >
                                Kirim ke pemohon
                            </AppButton>
                        </div>

                        <AppButton
                            v-if="request.allowed_transitions.includes('ditolak')"
                            variant="danger"
                            block
                            @click="openPanel = openPanel === 'tolak' ? null : 'tolak'"
                        >
                            <CircleX class="size-4" aria-hidden="true" />
                            Tolak pengajuan
                        </AppButton>

                        <div v-if="openPanel === 'tolak'" class="mt-3">
                            <TextareaField
                                v-model="rejectForm.reason"
                                label="Alasan penolakan"
                                required
                                :maxlength="1000"
                                :error="rejectForm.errors.reason"
                            />
                            <!-- Penolakan bersifat final: tidak ada jalan kembali
                                 dari status DITOLAK (mesin status LetterRequest). -->
                            <AppAlert type="warning" class="mt-2">
                                Penolakan bersifat final. Pemohon harus mengajukan ulang
                                sebagai permintaan baru.
                            </AppAlert>
                            <AppButton
                                variant="danger"
                                size="sm"
                                block
                                class="mt-2"
                                :loading="rejectForm.processing"
                                @click="reject"
                            >
                                Tolak sekarang
                            </AppButton>
                        </div>
                    </template>

                    <p
                        v-if="request.allowed_transitions.length === 0"
                        class="text-sm text-content-tertiary"
                    >
                        Pengajuan sudah selesai. Tidak ada tindakan yang tersisa.
                    </p>
                </section>

                <section class="card p-4 text-sm" aria-label="Keterangan waktu">
                    <dl class="space-y-2">
                        <div>
                            <dt class="text-content-tertiary">Diajukan</dt>
                            <dd>{{ formatDateTime(request.submitted_at) }}</dd>
                        </div>
                        <div>
                            <dt class="text-content-tertiary">Batas waktu</dt>
                            <dd :class="request.is_overdue ? 'font-medium text-danger' : ''">
                                {{ formatDateTime(request.due_at) }}
                            </dd>
                        </div>
                        <div v-if="request.completed_at">
                            <dt class="text-content-tertiary">Selesai</dt>
                            <dd>{{ formatDateTime(request.completed_at) }}</dd>
                        </div>
                        <div>
                            <dt class="text-content-tertiary">Kanal</dt>
                            <dd>{{ request.channel === 'walk_in' ? 'Loket desa' : 'Daring' }}</dd>
                        </div>
                    </dl>
                </section>

                <AppAlert v-if="request.revision_note" type="warning">
                    <p class="font-medium">Catatan revisi</p>
                    <p class="mt-1">{{ request.revision_note }}</p>
                </AppAlert>

                <AppAlert v-if="request.rejection_reason" type="danger">
                    <p class="font-medium">Alasan penolakan</p>
                    <p class="mt-1">{{ request.rejection_reason }}</p>
                </AppAlert>
            </aside>
        </div>
    </AppLayout>
</template>
