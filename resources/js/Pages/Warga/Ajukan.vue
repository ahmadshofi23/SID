<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import { Camera, Clock, Send, Upload, X, TriangleAlert } from '@lucide/vue'
import WargaLayout from '@/Layouts/WargaLayout.vue'
import AppButton from '@/Components/AppButton.vue'
import AppAlert from '@/Components/AppAlert.vue'
import SelectField from '@/Components/SelectField.vue'
import TextField from '@/Components/TextField.vue'
import DynamicFormFields from '@/Components/DynamicFormFields.vue'
import type {
    LetterFormField, LetterFormValues, LetterRequirement, UploadConstraints,
} from '@/types'

interface TypeOption {
    id: string
    code: string
    name: string
    description: string | null
    sla_days: number
    fee: number
    requirements: LetterRequirement[]
    fields: LetterFormField[]
    warning_note: string | null
}

interface Pemohon {
    id: string
    full_name: string
    nik_masked: string
    birth_date: string
    family_relation_code: string
    status: string
    is_me: boolean
}

const props = defineProps<{
    types: TypeOption[]
    selected_type_id: string | null
    upload: UploadConstraints
    pemohon: Pemohon[]
    berjalan: Record<string, string>
}>()

const saya = props.pemohon.find((p) => p.is_me) ?? props.pemohon[0] ?? null

const form = useForm({
    letter_type_id: props.selected_type_id ?? '',
    resident_id: saya?.id ?? '',
    purpose: '',
    form: {} as LetterFormValues,
    syarat: {} as Record<number, File | null>,
    konfirmasi_kembar: false as boolean,
})

const selectedType = computed(() =>
    props.types.find((t) => t.id === form.letter_type_id) ?? null)

const pemohonTerpilih = computed(() =>
    props.pemohon.find((p) => p.id === form.resident_id) ?? null)

/*
 * UC-05 7c: pemohon berstatus meninggal atau pindah ditolak server. Dicegah
 * lebih awal di sini supaya warga tidak mengisi seluruh formulir untuk
 * kemudian ditolak — tetapi pagarnya yang sebenarnya tetap di server.
 */
const pemohonTidakLayak = computed(() =>
    pemohonTerpilih.value !== null && pemohonTerpilih.value.status !== 'aktif')

/*
 * UC-05 7b: pengajuan sejenis yang masih berjalan MEMPERINGATKAN, tidak
 * memblokir. Alasan sah memang ada — satu surat untuk sekolah, satu untuk
 * bank, masing-masing perlu lembar asli.
 */
const tiketBerjalan = computed(() =>
    props.berjalan[`${form.letter_type_id}|${form.resident_id}`] ?? null)

watch(() => form.letter_type_id, () => {
    form.form = {}
    form.purpose = ''
    form.syarat = {}
    form.konfirmasi_kembar = false
    chosen.value = {}
    form.clearErrors()
})

watch(() => form.resident_id, () => {
    form.konfirmasi_kembar = false
})

const typeOptions = computed(() =>
    props.types.map((t) => ({ value: t.id, label: `${t.name} (${t.code})` })))

const pemohonOptions = computed(() =>
    props.pemohon.map((p) => ({
        value: p.id,
        label: p.is_me ? `${p.full_name} (saya)` : p.full_name,
    })))

const needsPurposeField = computed(() =>
    selectedType.value !== null
    && !selectedType.value.fields.some((f) => f.name === 'keperluan'))

const chosen = ref<Record<number, string>>({})

function pick(index: number, event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null

    form.syarat[index] = file
    chosen.value = { ...chosen.value, [index]: file?.name ?? '' }
    form.clearErrors(`syarat.${index}` as never)
}

function clearPick(index: number): void {
    form.syarat[index] = null
    chosen.value = { ...chosen.value, [index]: '' }
}

function uploadError(index: number): string | undefined {
    return (form.errors as Record<string, string>)[`syarat.${index}`]
}

function tanggal(iso: string): string {
    return new Date(iso).toLocaleDateString('id-ID', {
        day: 'numeric', month: 'long', year: 'numeric',
    })
}

function submit(): void {
    // forceFormData: lihat alasannya pada formulir loket — perilaku kirim
    // tidak boleh berbeda antara pengajuan dengan dan tanpa lampiran.
    form.post('/warga/ajukan', { preserveScroll: true, forceFormData: true })
}
</script>

<template>
    <Head title="Ajukan Surat" />

    <WargaLayout>
        <header class="mb-5">
            <h1 class="text-2xl font-semibold">Ajukan Surat</h1>
            <p class="mt-1 text-sm text-content-secondary">
                Isi dari rumah. Surat diambil di kantor desa setelah disetujui.
            </p>
        </header>

        <AppAlert v-if="types.length === 0" type="info" title="Belum ada layanan daring">
            Jenis surat yang dapat diajukan daring belum dibuka petugas desa.
            Anda tetap dapat mengurus surat di kantor desa.
        </AppAlert>

        <AppAlert v-else-if="pemohon.length === 0" type="warning" title="Data keluarga belum terlihat">
            Akun Anda belum tertaut ke satu Kartu Keluarga, sehingga pemohon tidak
            dapat dipilih. Hubungi kantor desa.
        </AppAlert>

        <form v-else class="space-y-5" @submit.prevent="submit">

            <section class="card p-4" aria-label="Jenis surat dan pemohon">
                <SelectField
                    v-model="form.letter_type_id"
                    label="Surat yang diperlukan"
                    required
                    :options="typeOptions"
                    :error="form.errors.letter_type_id"
                />

                <div class="mt-4">
                    <SelectField
                        v-model="form.resident_id"
                        label="Surat ini untuk"
                        required
                        :options="pemohonOptions"
                        :error="form.errors.resident_id"
                        hint="Anda sendiri, atau anggota Kartu Keluarga yang sama."
                    />
                </div>

                <!--
                  Identitas TERISI OTOMATIS DAN TERKUNCI (UC-05 langkah 3,
                  RULE-11). Bukan sekadar disabled: nilainya tidak pernah
                  dikirim dari peramban sama sekali — server membacanya
                  kembali dari data penduduk lewat repository berlingkup.
                -->
                <dl v-if="pemohonTerpilih"
                    class="mt-4 grid gap-3 rounded-lg bg-surface-sunken p-4 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-content-tertiary">Nama</dt>
                        <dd class="font-medium">{{ pemohonTerpilih.full_name }}</dd>
                    </div>
                    <div>
                        <dt class="text-content-tertiary">NIK</dt>
                        <dd class="tabular font-medium">{{ pemohonTerpilih.nik_masked }}</dd>
                    </div>
                    <div>
                        <dt class="text-content-tertiary">Tanggal lahir</dt>
                        <dd class="font-medium">{{ tanggal(pemohonTerpilih.birth_date) }}</dd>
                    </div>
                    <p class="text-xs text-content-tertiary sm:col-span-3">
                        Data ini diambil dari data kependudukan desa dan tidak dapat diubah
                        di sini. Bila ada yang keliru, hubungi kantor desa lebih dulu.
                    </p>
                </dl>
            </section>

            <AppAlert v-if="pemohonTidakLayak" type="danger" title="Surat tidak dapat diajukan">
                Status kependudukan orang yang Anda pilih bukan penduduk aktif.
                Hubungi kantor desa untuk keperluan surat atas namanya.
            </AppAlert>

            <AppAlert v-if="tiketBerjalan" type="warning" title="Sudah ada pengajuan yang sama berjalan">
                <p>
                    Pengajuan {{ selectedType?.name }} untuk orang ini masih diproses
                    (tiket {{ tiketBerjalan }}). Anda tetap boleh mengajukan lagi bila
                    memang memerlukan lembar kedua.
                </p>
                <label class="mt-3 flex cursor-pointer items-start gap-2.5 text-sm">
                    <input v-model="form.konfirmasi_kembar" type="checkbox"
                           class="mt-0.5 size-4.5 rounded border-line-strong accent-[var(--color-accent)]">
                    Ya, saya tetap ingin mengajukan surat ini sekali lagi.
                </label>
            </AppAlert>

            <AppAlert v-if="selectedType?.warning_note" type="warning" title="Perlu diperhatikan">
                {{ selectedType.warning_note }}
            </AppAlert>

            <section v-if="selectedType" class="card p-4" aria-label="Keterangan layanan">
                <p class="inline-flex items-center gap-1.5 text-sm text-content-secondary">
                    <Clock class="size-4" aria-hidden="true" />
                    Perkiraan selesai {{ selectedType.sla_days }} hari kerja ·
                    {{ selectedType.fee > 0 ? `Rp ${selectedType.fee.toLocaleString('id-ID')}` : 'Gratis' }}
                </p>
            </section>

            <section v-if="selectedType && selectedType.fields.length > 0"
                     class="card p-4" aria-label="Isian surat">
                <h2 class="mb-4 font-semibold">Keterangan yang diperlukan</h2>
                <DynamicFormFields
                    v-model="form.form"
                    :fields="selectedType.fields"
                    :errors="form.errors as Record<string, string>"
                />
            </section>

            <TextField
                v-if="needsPurposeField"
                v-model="form.purpose"
                label="Keperluan"
                :error="form.errors.purpose"
                hint="Akan tercetak di surat. Contoh: melamar pekerjaan."
            />

            <section v-if="selectedType && selectedType.requirements.length > 0"
                     class="card p-4" aria-label="Berkas syarat">
                <h2 class="font-semibold">Unggah syarat</h2>
                <p class="mt-1 text-xs text-content-tertiary">
                    {{ upload.types_label }}, maksimal {{ upload.max_label }} per berkas.
                    Syarat bertanda <strong>Wajib</strong> harus diunggah — berbeda dengan
                    pengurusan di loket, di sini tidak ada petugas yang dapat menyusulkannya.
                </p>

                <ul class="mt-4 space-y-4 text-sm">
                    <li v-for="(req, i) in selectedType.requirements" :key="i">
                        <p class="flex items-start gap-2">
                            <span class="mt-0.5 shrink-0 rounded px-1.5 py-0.5 text-xs font-medium"
                                  :class="req.required
                                      ? 'bg-danger-soft text-danger'
                                      : 'bg-surface-hover text-content-tertiary'">
                                {{ req.required ? 'Wajib' : 'Bila ada' }}
                            </span>
                            {{ req.label }}
                        </p>

                        <!--
                          TOMBOL KAMERA LEBIH DULU daripada pilih berkas
                          (docs/07 §5.3): warga memfoto dokumennya langsung,
                          bukan mencarinya di penyimpanan ponsel.
                        -->
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <label>
                                <span class="sr-only">Foto dokumen untuk: {{ req.label }}</span>
                                <input type="file" class="sr-only" capture="environment"
                                       :accept="upload.accept" @change="pick(i, $event)">
                                <span class="touch-target inline-flex cursor-pointer items-center gap-1.5
                                             rounded-lg bg-accent px-4 text-xs font-medium text-content-inverse
                                             hover:bg-accent-hover">
                                    <Camera class="size-4" aria-hidden="true" />
                                    Foto
                                </span>
                            </label>

                            <label>
                                <span class="sr-only">Pilih berkas untuk: {{ req.label }}</span>
                                <input type="file" class="sr-only"
                                       :accept="upload.accept" @change="pick(i, $event)">
                                <span class="touch-target inline-flex cursor-pointer items-center gap-1.5
                                             rounded-lg border border-line-control bg-surface px-4
                                             text-xs font-medium hover:bg-surface-hover">
                                    <Upload class="size-3.5" aria-hidden="true" />
                                    Pilih berkas
                                </span>
                            </label>

                            <span v-if="chosen[i]"
                                  class="inline-flex min-w-0 items-center gap-1 text-xs text-content-secondary">
                                <span class="truncate">{{ chosen[i] }}</span>
                                <button type="button"
                                        class="shrink-0 cursor-pointer rounded p-0.5 hover:text-danger"
                                        :aria-label="`Batalkan berkas untuk ${req.label}`"
                                        @click="clearPick(i)">
                                    <X class="size-3.5" aria-hidden="true" />
                                </button>
                            </span>
                        </div>

                        <p v-if="uploadError(i)" class="mt-1 text-xs text-danger" role="alert">
                            {{ uploadError(i) }}
                        </p>
                    </li>
                </ul>
            </section>

            <AppAlert v-if="form.hasErrors" type="danger" title="Pengajuan belum dapat dikirim">
                <span class="inline-flex items-center gap-1.5">
                    <TriangleAlert class="size-4" aria-hidden="true" />
                    Periksa bagian yang ditandai merah di atas.
                </span>
            </AppAlert>

            <div class="sticky bottom-20 sm:bottom-4">
                <AppButton type="submit" variant="primary" block
                           :loading="form.processing"
                           :disabled="!form.letter_type_id || !form.resident_id
                               || pemohonTidakLayak
                               || (tiketBerjalan !== null && !form.konfirmasi_kembar)">
                    <Send class="size-4" aria-hidden="true" />
                    Kirim pengajuan
                </AppButton>
            </div>
        </form>
    </WargaLayout>
</template>
