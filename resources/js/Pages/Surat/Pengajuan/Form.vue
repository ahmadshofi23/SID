<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { ArrowLeft, Clock, Send, Upload, X } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/AppButton.vue'
import AppAlert from '@/Components/AppAlert.vue'
import SelectField from '@/Components/SelectField.vue'
import TextField from '@/Components/TextField.vue'
import ResidentPicker from '@/Components/ResidentPicker.vue'
import DynamicFormFields from '@/Components/DynamicFormFields.vue'
import type {
    LetterFormField, LetterFormValues, LetterRequirement, UploadConstraints,
} from '@/types'

interface TypeOption {
    id: string
    code: string
    name: string
    sla_days: number
    requirements: LetterRequirement[]
    fields: LetterFormField[]
    warning_note: string | null
}

const props = defineProps<{
    types: TypeOption[]
    selected_type_id: string | null
    upload: UploadConstraints
}>()

const form = useForm({
    letter_type_id: props.selected_type_id ?? '',
    resident_id: null as string | null,
    purpose: '',
    form: {} as LetterFormValues,
    /* Berkas syarat, berkunci indeks syarat — lihat `syarat.<indeks>` di
       LetterRequestController::uploadedRequirementFiles(). */
    syarat: {} as Record<number, File | null>,
})

const selectedType = computed(() =>
    props.types.find((t) => t.id === form.letter_type_id) ?? null)

/*
 * Isian dinamis DIKOSONGKAN saat jenis surat berganti.
 *
 * Nama field kebetulan sama antar jenis surat (`keperluan`, `nama_saksi_1`),
 * jadi tanpa pengosongan ini nilai dari jenis sebelumnya akan ikut terkirim
 * dan tercetak di surat yang salah.
 */
watch(() => form.letter_type_id, () => {
    form.form = {}
    form.purpose = ''
    /* Berkas ikut dikosongkan: daftar syarat jenis surat berikutnya berbeda,
       dan berkas yang tertinggal akan masuk sebagai syarat yang salah. */
    form.syarat = {}
    form.clearErrors()
})

const typeOptions = computed(() =>
    props.types.map((t) => ({ value: t.id, label: `${t.code} — ${t.name}` })))

/* Keperluan hanya ditanyakan di luar formulir bila jenis suratnya tidak
   sudah memiliki field `keperluan` sendiri — kalau tidak, petugas akan
   melihat dua kotak dengan pertanyaan yang sama. */
const needsPurposeField = computed(() =>
    selectedType.value !== null
    && !selectedType.value.fields.some((f) => f.name === 'keperluan'))

/** Berkas terpilih per syarat, untuk ditampilkan kembali di daftar. */
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

/** Galat per kotak berkas, dikirim server dengan kunci `syarat.<indeks>`. */
function uploadError(index: number): string | undefined {
    return (form.errors as Record<string, string>)[`syarat.${index}`]
}

function submit(): void {
    /*
     * forceFormData WAJIB di sini.
     *
     * Inertia hanya beralih ke FormData bila ada berkas pada payload, dan
     * pengajuan tanpa lampiran sama sahnya dengan yang berlampiran. Tanpa
     * ini, perilaku kirimnya berbeda-beda tergantung ada tidaknya berkas —
     * dan hanya satu dari dua jalur itu yang pernah diuji tangan.
     */
    form.post('/surat', { preserveScroll: true, forceFormData: true })
}
</script>

<template>
    <Head title="Ajukan Surat" />

    <AppLayout>
        <Link
            href="/surat"
            class="mb-4 inline-flex items-center gap-1.5 text-sm text-content-secondary hover:text-content"
        >
            <ArrowLeft class="size-4" aria-hidden="true" />
            Kembali ke antrean
        </Link>

        <header class="mb-5">
            <h1 class="text-2xl font-semibold">Ajukan Surat di Loket</h1>
            <p class="mt-1 text-sm text-content-secondary">
                Untuk warga yang datang langsung ke kantor desa. Pengajuan langsung
                masuk antrean pemeriksaan.
            </p>
        </header>

        <AppAlert v-if="types.length === 0" type="warning">
            Belum ada jenis surat yang aktif. Aktifkan dari
            <Link href="/jenis-surat" class="underline">Jenis Surat</Link> lebih dulu.
        </AppAlert>

        <form v-else class="grid gap-5 lg:grid-cols-3" @submit.prevent="submit">
            <div class="space-y-5 lg:col-span-2">
                <section class="card p-4" aria-label="Jenis surat & pemohon">
                    <div class="grid gap-4">
                        <SelectField
                            v-model="form.letter_type_id"
                            label="Jenis surat"
                            required
                            :options="typeOptions"
                            :error="form.errors.letter_type_id"
                        />

                        <ResidentPicker
                            v-model="form.resident_id"
                            label="Pemohon"
                            required
                            :error="form.errors.resident_id"
                        />

                        <TextField
                            v-if="needsPurposeField"
                            v-model="form.purpose"
                            label="Keperluan"
                            :error="form.errors.purpose"
                            hint="Akan tercetak di surat."
                        />
                    </div>
                </section>

                <section
                    v-if="selectedType && selectedType.fields.length > 0"
                    class="card p-4"
                    aria-label="Isian surat"
                >
                    <h2 class="mb-4 font-semibold">Isian {{ selectedType.code }}</h2>
                    <DynamicFormFields
                        v-model="form.form"
                        :fields="selectedType.fields"
                        :errors="form.errors as Record<string, string>"
                    />
                </section>

                <div class="flex flex-wrap gap-2">
                    <AppButton
                        type="submit"
                        variant="primary"
                        :loading="form.processing"
                        :disabled="!form.letter_type_id || !form.resident_id"
                    >
                        <Send class="size-4" aria-hidden="true" />
                        Buat pengajuan
                    </AppButton>
                    <AppButton href="/surat" variant="ghost">Batal</AppButton>
                </div>
            </div>

            <!-- Panel syarat: petugas membacanya sambil memeriksa berkas fisik -->
            <aside v-if="selectedType" class="space-y-5">
                <AppAlert v-if="selectedType.warning_note" type="warning">
                    <p class="font-medium">Surat ini perlu kehati-hatian</p>
                    <p class="mt-1">{{ selectedType.warning_note }}</p>
                </AppAlert>

                <section class="card p-4" aria-label="Keterangan jenis surat">
                    <h2 class="mb-2 font-semibold">{{ selectedType.name }}</h2>
                    <p class="inline-flex items-center gap-1.5 text-sm text-content-secondary">
                        <Clock class="size-4" aria-hidden="true" />
                        Target selesai {{ selectedType.sla_days }} hari kerja
                    </p>
                </section>

                <section
                    v-if="selectedType.requirements.length > 0"
                    class="card p-4"
                    aria-label="Syarat berkas"
                >
                    <h2 class="mb-1 font-semibold">Syarat berkas</h2>
                    <p class="mb-3 text-xs text-content-tertiary">
                        {{ upload.types_label }}, maksimal {{ upload.max_label }} per berkas.
                        Berkas boleh menyusul — pengajuan tetap dapat dibuat tanpanya.
                    </p>

                    <ul class="space-y-3 text-sm">
                        <li v-for="(req, i) in selectedType.requirements" :key="i">
                            <p class="flex items-start gap-2">
                                <span
                                    class="mt-0.5 shrink-0 rounded px-1.5 py-0.5 text-xs font-medium"
                                    :class="req.required
                                        ? 'bg-danger-soft text-danger'
                                        : 'bg-surface-hover text-content-tertiary'"
                                >
                                    {{ req.required ? 'Wajib' : 'Opsional' }}
                                </span>
                                {{ req.label }}
                            </p>

                            <div class="mt-1.5 flex flex-wrap items-center gap-2">
                                <label>
                                    <span class="sr-only">Pilih berkas untuk: {{ req.label }}</span>
                                    <input
                                        type="file"
                                        class="sr-only"
                                        :accept="upload.accept"
                                        @change="pick(i, $event)"
                                    >
                                    <span
                                        class="inline-flex h-9 cursor-pointer items-center gap-1.5
                                               rounded-lg border border-line-control bg-surface px-3
                                               text-xs font-medium hover:bg-surface-hover"
                                    >
                                        <Upload class="size-3.5" aria-hidden="true" />
                                        {{ chosen[i] ? 'Ganti' : 'Pilih berkas' }}
                                    </span>
                                </label>

                                <span
                                    v-if="chosen[i]"
                                    class="inline-flex min-w-0 items-center gap-1 text-xs text-content-secondary"
                                >
                                    <span class="truncate">{{ chosen[i] }}</span>
                                    <button
                                        type="button"
                                        class="shrink-0 cursor-pointer rounded p-0.5 hover:text-danger"
                                        :aria-label="`Batalkan berkas untuk ${req.label}`"
                                        @click="clearPick(i)"
                                    >
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
            </aside>
        </form>
    </AppLayout>
</template>
