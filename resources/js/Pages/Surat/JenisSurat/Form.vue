<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { ArrowLeft, Eye, Save, Copy, TriangleAlert } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/AppButton.vue'
import AppAlert from '@/Components/AppAlert.vue'
import TextField from '@/Components/TextField.vue'
import TextareaField from '@/Components/TextareaField.vue'
import type { LetterFormField, LetterRequirement } from '@/types'

interface ApprovalStep {
    step?: number
    action?: string
    role?: string
    roles?: string[]
}

const props = defineProps<{
    type: {
        id: string
        code: string
        name: string
        title_on_letter: string
        classification_code: string | null
        description: string | null
        number_pattern: string
        sla_days: number
        validity_months: number | null
        fee: number
        is_active: boolean
        is_public: boolean
        requires_field_check: boolean
        warning_note: string | null
        template_html: string
        requirements: LetterRequirement[]
        approval_flow: ApprovalStep[]
        form_fields: LetterFormField[]
    }
    available_variables: Record<string, string[]>
}>()

const form = useForm({
    name: props.type.name,
    title_on_letter: props.type.title_on_letter,
    classification_code: props.type.classification_code ?? '',
    description: props.type.description ?? '',
    number_pattern: props.type.number_pattern,
    sla_days: props.type.sla_days,
    validity_months: props.type.validity_months,
    fee: props.type.fee,
    is_active: props.type.is_active,
    is_public: props.type.is_public,
    requires_field_check: props.type.requires_field_check,
    warning_note: props.type.warning_note ?? '',
    template_html: props.type.template_html,
})

/* ---------------------------- Pratinjau ---------------------------- */

const previewHtml = ref<string | null>(null)
const previewing = ref(false)
const previewError = ref<string | null>(null)

async function preview(): Promise<void> {
    previewing.value = true
    previewError.value = null

    try {
        const response = await fetch('/jenis-surat/pratinjau', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
            },
            body: JSON.stringify({
                template_html: form.template_html,
                letter_type_id: props.type.id,
            }),
        })

        if (!response.ok) throw new Error(`HTTP ${response.status}`)

        const body = await response.json() as { html: string }

        previewHtml.value = body.html
    } catch {
        /*
         * Pratinjau gagal TIDAK boleh menampilkan template apa adanya sebagai
         * pengganti. Hasil pratinjau adalah HTML yang sudah dibersihkan server;
         * menampilkan masukan mentah di sini akan menjadikan layar ini jalan
         * menjalankan skrip yang justru dicegah pembersih itu.
         */
        previewHtml.value = null
        previewError.value = 'Pratinjau gagal dimuat. Periksa sambungan lalu coba lagi.'
    } finally {
        previewing.value = false
    }
}

/* -------------------------- Bantuan variabel ------------------------- */

const copied = ref<string | null>(null)

async function copyVariable(name: string, group: string): Promise<void> {
    // Pemformat ditulis sebagai pasangan buka-tutup, bukan variabel tunggal.
    const snippet = group === 'Pemformat'
        ? `{{#${name}}}{{nama}}{{/${name}}}`
        : `{{${name}}}`

    try {
        await navigator.clipboard.writeText(snippet)
        copied.value = name
        setTimeout(() => { copied.value = null }, 1500)
    } catch {
        copied.value = null
    }
}

const patternIsValid = computed(() =>
    form.number_pattern.includes('{urut}') || /\{urut:\d\}/.test(form.number_pattern))

function submit(): void {
    form.put(`/jenis-surat/${props.type.id}`)
}
</script>

<template>
    <Head :title="`Jenis Surat ${type.code}`" />

    <AppLayout>
        <Link
            href="/jenis-surat"
            class="mb-4 inline-flex items-center gap-1.5 text-sm text-content-secondary hover:text-content"
        >
            <ArrowLeft class="size-4" aria-hidden="true" />
            Kembali ke daftar jenis surat
        </Link>

        <header class="mb-5">
            <p class="font-mono text-sm text-content-tertiary">{{ type.code }}</p>
            <h1 class="text-2xl font-semibold">{{ type.name }}</h1>
        </header>

        <form class="grid gap-5 lg:grid-cols-3" @submit.prevent="submit">
            <div class="space-y-5 lg:col-span-2">
                <!-- Keterangan dasar -->
                <section class="card p-4" aria-label="Keterangan dasar">
                    <h2 class="mb-4 font-semibold">Keterangan</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <TextField
                            v-model="form.name"
                            label="Nama jenis surat"
                            required
                            :error="form.errors.name"
                        />
                        <TextField
                            v-model="form.title_on_letter"
                            label="Judul tercetak di surat"
                            required
                            :error="form.errors.title_on_letter"
                            hint="Biasanya huruf kapital seluruhnya."
                        />
                        <div class="sm:col-span-2">
                            <TextareaField
                                v-model="form.description"
                                label="Keterangan singkat"
                                :rows="2"
                                :maxlength="1000"
                                :error="form.errors.description"
                                hint="Tampil di daftar jenis surat dan portal warga."
                            />
                        </div>
                    </div>
                </section>

                <!-- Penomoran -->
                <section class="card p-4" aria-label="Penomoran">
                    <h2 class="mb-1 font-semibold">Penomoran</h2>
                    <p class="mb-4 text-sm text-content-secondary">
                        Sesuaikan dengan arsip surat asli desa sebelum surat pertama terbit.
                    </p>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <TextField
                                v-model="form.number_pattern"
                                label="Pola nomor"
                                required
                                :error="form.errors.number_pattern"
                            />
                            <p v-if="!patternIsValid" class="mt-1 flex items-start gap-1.5 text-sm text-danger">
                                <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                Pola wajib memuat <code>{urut}</code> atau <code>{urut:3}</code>.
                                Tanpa nomor urut, surat kedua tidak dapat diterbitkan.
                            </p>
                            <p v-else class="mt-1 text-sm text-content-tertiary">
                                Token: <code>{urut}</code> <code>{urut:3}</code>
                                <code>{kode}</code> <code>{klasifikasi}</code>
                                <code>{kode_desa}</code> <code>{bulan}</code>
                                <code>{bulan_romawi}</code> <code>{tahun}</code>
                                <code>{tahun2}</code>
                            </p>
                        </div>

                        <TextField
                            v-model="form.classification_code"
                            label="Kode klasifikasi arsip"
                            :error="form.errors.classification_code"
                            hint="Ambil dari arsip desa. Jangan ditebak — kode yang salah terbawa bertahun-tahun."
                            mono
                        />
                    </div>
                </section>

                <!-- Template -->
                <section class="card p-4" aria-label="Template surat">
                    <h2 class="mb-1 font-semibold">Isi pokok surat</h2>
                    <p class="mb-4 text-sm text-content-secondary">
                        Tuliskan HANYA isi pokoknya. Kop surat, blok identitas pemohon,
                        dan blok tanda tangan ditambahkan otomatis.
                    </p>

                    <label for="template" class="mb-1.5 block text-sm font-medium">
                        Template HTML
                        <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <textarea
                        id="template"
                        v-model="form.template_html"
                        rows="14"
                        spellcheck="false"
                        class="w-full rounded-lg border bg-surface p-3 font-mono text-sm leading-relaxed
                               transition-colors duration-200
                               focus:border-accent focus:outline-none"
                        :class="form.errors.template_html
                            ? 'border-danger'
                            : 'border-line-control hover:border-content-tertiary'"
                    />
                    <p v-if="form.errors.template_html" class="mt-1 text-sm text-danger" role="alert">
                        {{ form.errors.template_html }}
                    </p>

                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <AppButton :loading="previewing" @click="preview">
                            <Eye class="size-4" aria-hidden="true" />
                            Pratinjau
                        </AppButton>
                        <p class="text-xs text-content-tertiary">
                            Pratinjau memakai data contoh, bukan data warga.
                        </p>
                    </div>

                    <AppAlert v-if="previewError" type="danger" class="mt-3">
                        {{ previewError }}
                    </AppAlert>

                    <div v-else-if="previewHtml !== null" class="mt-3">
                        <p class="mb-2 text-sm font-medium">Hasil pratinjau</p>
                        <!-- Aman: previewHtml adalah HTML yang sudah dibersihkan
                             server (App\Support\HtmlSanitizer), bukan isi textarea. -->
                        <!-- eslint-disable-next-line vue/no-v-html -->
                        <div
                            class="prose-letter rounded-lg border border-line bg-white p-5 text-black"
                            v-html="previewHtml"
                        />
                    </div>
                </section>

                <!-- Pelayanan -->
                <section class="card p-4" aria-label="Pengaturan pelayanan">
                    <h2 class="mb-4 font-semibold">Pelayanan</h2>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <TextField
                            v-model="form.sla_days"
                            label="Target selesai (hari kerja)"
                            type="number"
                            inputmode="numeric"
                            required
                            :error="form.errors.sla_days"
                        />
                        <TextField
                            v-model="form.validity_months"
                            label="Masa berlaku (bulan)"
                            type="number"
                            inputmode="numeric"
                            :error="form.errors.validity_months"
                            hint="Kosongkan bila tanpa batas."
                        />
                        <TextField
                            v-model="form.fee"
                            label="Biaya (Rp)"
                            type="number"
                            inputmode="numeric"
                            required
                            :error="form.errors.fee"
                        />
                    </div>

                    <div class="mt-4 space-y-2">
                        <label class="flex min-h-11 items-center gap-2.5 text-sm">
                            <input v-model="form.is_active" type="checkbox" class="size-4 rounded border-line-control">
                            Aktif — dapat dipakai petugas
                        </label>
                        <label class="flex min-h-11 items-center gap-2.5 text-sm">
                            <input v-model="form.is_public" type="checkbox" class="size-4 rounded border-line-control">
                            Dapat diajukan warga secara daring
                        </label>
                        <label class="flex min-h-11 items-center gap-2.5 text-sm">
                            <input
                                v-model="form.requires_field_check"
                                type="checkbox"
                                class="size-4 rounded border-line-control"
                            >
                            Wajib pengecekan lapangan sebelum terbit
                        </label>
                    </div>

                    <div class="mt-4">
                        <TextareaField
                            v-model="form.warning_note"
                            label="Peringatan di layar persetujuan"
                            :rows="3"
                            :maxlength="1000"
                            :error="form.errors.warning_note"
                            hint="Bila diisi, penyetuju wajib mencentang bahwa ia sudah membacanya."
                        />
                    </div>
                </section>

                <div class="flex flex-wrap gap-2">
                    <AppButton
                        type="submit"
                        variant="primary"
                        :loading="form.processing"
                        :disabled="!patternIsValid"
                    >
                        <Save class="size-4" aria-hidden="true" />
                        Simpan perubahan
                    </AppButton>
                    <AppButton href="/jenis-surat" variant="ghost">Batal</AppButton>
                </div>
            </div>

            <!-- ============ Panel bantuan ============ -->
            <aside class="space-y-5">
                <section class="card p-4" aria-label="Daftar variabel">
                    <h2 class="mb-1 font-semibold">Variabel yang tersedia</h2>
                    <p class="mb-3 text-xs text-content-secondary">
                        Klik untuk menyalin. Variabel di luar daftar ini akan
                        ditolak saat disimpan.
                    </p>

                    <div v-for="(names, group) in available_variables" :key="group" class="mb-4 last:mb-0">
                        <h3 class="mb-1.5 text-xs font-medium uppercase text-content-tertiary">
                            {{ group }}
                        </h3>
                        <p v-if="names.length === 0" class="text-xs text-content-tertiary">
                            Belum ada.
                        </p>
                        <ul v-else class="flex flex-wrap gap-1">
                            <li v-for="name in names" :key="name">
                                <button
                                    type="button"
                                    class="cursor-pointer rounded bg-surface-hover px-1.5 py-1 font-mono
                                           text-xs transition-colors hover:bg-accent-soft hover:text-accent"
                                    @click="copyVariable(name, String(group))"
                                >
                                    <Copy
                                        v-if="copied === name"
                                        class="mr-1 inline size-3"
                                        aria-hidden="true"
                                    />{{ name }}
                                </button>
                            </li>
                        </ul>
                    </div>
                </section>

                <!-- Alur persetujuan: ditampilkan, belum dapat disunting.
                     Mengubah alur mengubah siapa yang berwenang menerbitkan
                     surat, jadi penyuntingannya menunggu Sprint 7 bersama
                     pengaturan peran. -->
                <section class="card p-4" aria-label="Alur persetujuan">
                    <h2 class="mb-3 font-semibold">Alur persetujuan</h2>
                    <ol class="space-y-2 text-sm">
                        <li
                            v-for="(step, i) in type.approval_flow"
                            :key="i"
                            class="flex items-start gap-2"
                        >
                            <span
                                class="flex size-5 shrink-0 items-center justify-center rounded-full
                                       bg-surface-hover text-xs tabular"
                            >
                                {{ i + 1 }}
                            </span>
                            <span>
                                {{ step.role ?? (step.roles ?? []).join(', ') ?? 'admin_desa' }}
                                <span class="text-content-tertiary">— {{ step.action }}</span>
                            </span>
                        </li>
                        <li v-if="type.approval_flow.length === 0" class="text-content-tertiary">
                            Satu tahap: Admin Desa / Kepala Desa.
                        </li>
                    </ol>
                    <p class="mt-3 text-xs text-content-tertiary">
                        Penyuntingan alur tersedia pada Sprint 7, bersama pengaturan peran.
                    </p>
                </section>

                <section
                    v-if="type.form_fields.length > 0"
                    class="card p-4"
                    aria-label="Isian dinamis"
                >
                    <h2 class="mb-3 font-semibold">Isian yang diminta</h2>
                    <ul class="space-y-1.5 text-sm">
                        <li v-for="field in type.form_fields" :key="field.name">
                            <span class="font-mono text-xs">form.{{ field.name }}</span>
                            <span class="text-content-tertiary"> — {{ field.label }}</span>
                            <span v-if="field.required" class="text-danger" aria-hidden="true">*</span>
                        </li>
                    </ul>
                </section>
            </aside>
        </form>
    </AppLayout>
</template>

<style scoped>
/* Pratinjau memakai serif seperti surat tercetak, agar panjang baris dan
   pemenggalan kata mendekati hasil cetak yang sebenarnya. */
.prose-letter {
    font-family: 'Times New Roman', Times, serif;
    font-size: 12pt;
    line-height: 1.5;
}

.prose-letter :deep(p) { margin-bottom: 0.75rem; }
.prose-letter :deep(table) { margin: 0.5rem 0 0.75rem 1.5rem; }
.prose-letter :deep(td) { padding: 0.1rem 0.4rem 0.1rem 0; vertical-align: top; }
.prose-letter :deep(pre) { font-family: inherit; white-space: pre-wrap; }
</style>
