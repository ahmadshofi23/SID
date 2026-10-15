<script setup lang="ts">
import { computed, watch } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { ArrowLeft, TriangleAlert, Clock, Send } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/AppButton.vue'
import AppAlert from '@/Components/AppAlert.vue'
import SelectField from '@/Components/SelectField.vue'
import TextField from '@/Components/TextField.vue'
import ResidentPicker from '@/Components/ResidentPicker.vue'
import DynamicFormFields from '@/Components/DynamicFormFields.vue'
import type { LetterFormField, LetterFormValues, LetterRequirement } from '@/types'

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
}>()

const form = useForm({
    letter_type_id: props.selected_type_id ?? '',
    resident_id: null as string | null,
    purpose: '',
    form: {} as LetterFormValues,
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

function submit(): void {
    form.post('/surat', { preserveScroll: true })
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
                    <h2 class="mb-3 font-semibold">Syarat berkas</h2>
                    <ul class="space-y-2 text-sm">
                        <li
                            v-for="(req, i) in selectedType.requirements"
                            :key="i"
                            class="flex items-start gap-2"
                        >
                            <span
                                class="mt-0.5 shrink-0 rounded px-1.5 py-0.5 text-xs font-medium"
                                :class="req.required
                                    ? 'bg-danger-soft text-danger'
                                    : 'bg-surface-hover text-content-tertiary'"
                            >
                                {{ req.required ? 'Wajib' : 'Opsional' }}
                            </span>
                            {{ req.label }}
                        </li>
                    </ul>
                    <p class="mt-3 inline-flex items-start gap-1.5 text-xs text-content-tertiary">
                        <TriangleAlert class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                        Unggahan berkas tersedia pada Sprint 5. Periksa berkas fisik
                        yang dibawa pemohon.
                    </p>
                </section>
            </aside>
        </form>
    </AppLayout>
</template>
