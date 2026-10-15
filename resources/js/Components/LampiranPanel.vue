<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import {
    Paperclip, Upload, Trash2, FileText, Image as ImageIcon,
    Download, TriangleAlert, Lock,
} from '@lucide/vue'
import AppAlert from './AppAlert.vue'
import type { LetterAttachment, LetterRequirement, UploadConstraints } from '@/types'

const props = defineProps<{
    requestId: string
    requirements: LetterRequirement[]
    attachments: LetterAttachment[]
    upload: UploadConstraints
    /** Unggah & hapus hanya selama surat belum terbit (ADR-003). */
    editable: boolean
}>()

/*
 * Pengelompokan berkas berdasarkan INDEKS syarat, dengan satu kelompok
 * tambahan untuk sisanya.
 *
 * Kelompok "lainnya" bukan hiasan: berkas yang diunggah sebelum daftar
 * syarat disunting dapat menunjuk indeks yang kini di luar jangkauan.
 * Tanpa kelompok itu, berkas tersebut hilang dari layar sementara barisnya
 * tetap ada di basis data — dan tidak ada yang dapat menghapusnya.
 */
const byRequirement = computed(() => {
    const map = new Map<number, LetterAttachment[]>()

    for (const file of props.attachments) {
        const i = file.requirement_index

        if (i === null || i >= props.requirements.length) continue

        map.set(i, [...(map.get(i) ?? []), file])
    }

    return map
})

const others = computed(() => props.attachments.filter(
    (f) => f.requirement_index === null || f.requirement_index >= props.requirements.length))

const missingRequired = computed(() => props.requirements
    .filter((req, i) => req.required && (byRequirement.value.get(i) ?? []).length === 0))

const uploadForm = useForm<{ berkas: File | null; requirement_index: number | null }>({
    berkas: null,
    requirement_index: null,
})

/** Indeks syarat yang kotak unggahnya sedang diproses. */
const busyIndex = ref<number | null>(null)

/*
 * Kotak mana yang memiliki galat terakhir — disimpan terpisah.
 *
 * Tidak dapat disimpulkan dari uploadForm: setelah unggahan selesai,
 * busyIndex dan requirement_index sama-sama kembali ke null, sehingga
 * galat milik syarat ke-1 juga tampil di kotak "berkas lain". Petugas
 * melihat pesan yang sama dua kali dan menduga ada dua berkas yang gagal.
 *
 * Nilai `false` berarti tidak ada galat; `null` berarti kotak "berkas lain".
 */
const errorIndex = ref<number | null | false>(false)

function pick(index: number | null, event: Event): void {
    const input = event.target as HTMLInputElement
    const file = input.files?.[0]

    if (!file) return

    uploadForm.berkas = file
    uploadForm.requirement_index = index
    busyIndex.value = index
    errorIndex.value = false

    uploadForm.post(`/surat/${props.requestId}/lampiran`, {
        preserveScroll: true,
        forceFormData: true,
        /*
         * Kotak berkas DIKOSONGKAN apa pun hasilnya.
         *
         * Peramban tidak mengirim ulang berkas yang sama pada `change`
         * berikutnya bila nilainya tidak berubah, sehingga petugas yang
         * memperbaiki berkas lalu memilih berkas bernama sama tidak akan
         * memicu unggahan apa pun — dan menyimpulkan tombolnya rusak.
         */
        onError: () => { errorIndex.value = index },
        onFinish: () => {
            input.value = ''
            busyIndex.value = null
            uploadForm.reset()
        },
    })
}

function remove(file: LetterAttachment): void {
    if (!window.confirm(`Hapus berkas "${file.original_name}"?`)) return

    router.delete(`/surat/${props.requestId}/lampiran/${file.id}`, { preserveScroll: true })
}

/** Galat unggahan untuk kotak tertentu — ditampilkan di tempat kejadian. */
function errorFor(index: number | null): string | undefined {
    if (errorIndex.value !== index) return undefined

    /* `lampiran` adalah kunci galat dari LetterAttachmentService saat surat
       sudah terbit — ia tidak ada di antara field formulir ini, jadi tipenya
       dilonggarkan di sini alih-alih menambah field palsu. */
    const errors = uploadForm.errors as Record<string, string | undefined>

    return errors.berkas ?? errors.lampiran
}
</script>

<template>
    <section class="card p-4" aria-label="Lampiran syarat">
        <h2 class="mb-3 flex items-center gap-2 font-semibold">
            <Paperclip class="size-4 text-content-tertiary" aria-hidden="true" />
            Berkas syarat
        </h2>

        <p class="mb-3 text-sm text-content-secondary">
            {{ upload.types_label }}, maksimal {{ upload.max_label }} per berkas.
            Berkas disimpan tertutup dan hanya dapat dibuka petugas berwenang.
        </p>

        <!-- Surat terbit: lampiran ikut menjadi arsip (ADR-003) -->
        <AppAlert v-if="!editable" type="info" class="mb-3">
            <p class="flex items-center gap-1.5">
                <Lock class="size-3.5 shrink-0" aria-hidden="true" />
                Surat sudah terbit. Lampirannya menjadi bagian arsip dan tidak
                dapat diubah lagi.
            </p>
        </AppAlert>

        <!-- Syarat wajib yang belum ada berkasnya: diangkat ke atas, bukan
             dibiarkan tersembunyi di tengah daftar panjang. -->
        <AppAlert v-else-if="missingRequired.length > 0" type="warning" class="mb-3">
            <p class="font-medium">
                {{ missingRequired.length }} syarat wajib belum ada berkasnya
            </p>
            <p class="mt-1">
                Pengajuan tetap dapat diproses bila berkas fisiknya sudah
                diperiksa di loket.
            </p>
        </AppAlert>

        <ul class="space-y-3">
            <li
                v-for="(req, i) in requirements"
                :key="i"
                class="rounded-lg border border-line p-3"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <p class="flex items-start gap-2 text-sm">
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

                    <label v-if="editable" class="shrink-0">
                        <span class="sr-only">Unggah berkas untuk: {{ req.label }}</span>
                        <input
                            type="file"
                            class="sr-only"
                            :accept="upload.accept"
                            :disabled="uploadForm.processing"
                            @change="pick(i, $event)"
                        >
                        <span
                            class="inline-flex h-9 cursor-pointer items-center gap-1.5 rounded-lg
                                   border border-line-control bg-surface px-3 text-sm font-medium
                                   hover:bg-surface-hover"
                            :class="uploadForm.processing ? 'pointer-events-none opacity-50' : ''"
                        >
                            <Upload class="size-4" aria-hidden="true" />
                            {{ busyIndex === i ? 'Mengunggah…' : 'Unggah' }}
                        </span>
                    </label>
                </div>

                <p v-if="errorFor(i)" class="mt-2 text-sm text-danger" role="alert">
                    {{ errorFor(i) }}
                </p>

                <ul
                    v-if="(byRequirement.get(i) ?? []).length > 0"
                    class="mt-2 space-y-1.5"
                >
                    <li
                        v-for="file in byRequirement.get(i)"
                        :key="file.id"
                        class="flex flex-wrap items-center gap-2 rounded bg-surface-hover px-2 py-1.5 text-sm"
                    >
                        <component
                            :is="file.is_image ? ImageIcon : FileText"
                            class="size-4 shrink-0 text-content-tertiary"
                            aria-hidden="true"
                        />
                        <span class="min-w-0 flex-1 truncate">{{ file.original_name }}</span>
                        <span class="shrink-0 text-xs tabular text-content-tertiary">
                            {{ file.size_label }}
                        </span>

                        <!-- Berkas yang hilang dari penyimpanan dikatakan
                             terus terang, bukan ditawarkan sebagai tautan
                             yang pasti gagal (KEP-013). -->
                        <span
                            v-if="!file.tersedia"
                            class="inline-flex shrink-0 items-center gap-1 text-xs font-medium text-danger"
                        >
                            <TriangleAlert class="size-3.5" aria-hidden="true" />
                            Hilang dari penyimpanan
                        </span>

                        <template v-else>
                            <a
                                v-if="file.is_image"
                                :href="`${file.url}?pratinjau=1`"
                                target="_blank"
                                rel="noopener"
                                class="shrink-0 text-xs font-medium text-accent hover:underline"
                            >
                                Lihat
                            </a>
                            <a
                                :href="file.url"
                                class="inline-flex shrink-0 items-center gap-1 text-xs font-medium
                                       text-accent hover:underline"
                            >
                                <Download class="size-3.5" aria-hidden="true" />
                                Unduh
                            </a>
                        </template>

                        <button
                            v-if="editable"
                            type="button"
                            class="shrink-0 cursor-pointer rounded p-1 text-content-tertiary
                                   hover:bg-danger-soft hover:text-danger"
                            :aria-label="`Hapus berkas ${file.original_name}`"
                            @click="remove(file)"
                        >
                            <Trash2 class="size-4" aria-hidden="true" />
                        </button>
                    </li>
                </ul>

                <p v-else class="mt-2 text-xs text-content-tertiary">
                    Belum ada berkas diunggah.
                </p>
            </li>
        </ul>

        <!-- Berkas tambahan & berkas yang syaratnya sudah tidak ada lagi -->
        <div v-if="others.length > 0" class="mt-3 rounded-lg border border-line p-3">
            <p class="text-sm font-medium">Berkas lain</p>
            <p class="mt-0.5 text-xs text-content-tertiary">
                Berkas tambahan, atau berkas yang syaratnya sudah dihapus dari
                jenis surat ini.
            </p>
            <ul class="mt-2 space-y-1.5">
                <li
                    v-for="file in others"
                    :key="file.id"
                    class="flex flex-wrap items-center gap-2 rounded bg-surface-hover px-2 py-1.5 text-sm"
                >
                    <component
                        :is="file.is_image ? ImageIcon : FileText"
                        class="size-4 shrink-0 text-content-tertiary"
                        aria-hidden="true"
                    />
                    <span class="min-w-0 flex-1 truncate">{{ file.original_name }}</span>
                    <span v-if="file.requirement_label" class="shrink-0 text-xs text-content-tertiary">
                        {{ file.requirement_label }}
                    </span>
                    <span
                        v-if="!file.tersedia"
                        class="inline-flex shrink-0 items-center gap-1 text-xs font-medium text-danger"
                    >
                        <TriangleAlert class="size-3.5" aria-hidden="true" />
                        Hilang dari penyimpanan
                    </span>
                    <a
                        v-else
                        :href="file.url"
                        class="shrink-0 text-xs font-medium text-accent hover:underline"
                    >
                        Unduh
                    </a>
                    <button
                        v-if="editable"
                        type="button"
                        class="shrink-0 cursor-pointer rounded p-1 text-content-tertiary
                               hover:bg-danger-soft hover:text-danger"
                        :aria-label="`Hapus berkas ${file.original_name}`"
                        @click="remove(file)"
                    >
                        <Trash2 class="size-4" aria-hidden="true" />
                    </button>
                </li>
            </ul>
        </div>

        <!-- Berkas tambahan di luar daftar syarat -->
        <label v-if="editable" class="mt-3 inline-block">
            <span class="sr-only">Unggah berkas tambahan di luar daftar syarat</span>
            <input
                type="file"
                class="sr-only"
                :accept="upload.accept"
                :disabled="uploadForm.processing"
                @change="pick(null, $event)"
            >
            <!-- Span, bukan AppButton: tombol di dalam <label> menelan
                 kliknya dan kotak berkas tidak pernah terbuka. -->
            <span
                class="inline-flex h-9 cursor-pointer items-center gap-1.5 rounded-lg px-3
                       text-sm font-medium text-content-secondary hover:bg-surface-hover
                       hover:text-content"
                :class="uploadForm.processing ? 'pointer-events-none opacity-50' : ''"
            >
                <Upload class="size-4" aria-hidden="true" />
                {{ busyIndex === null && uploadForm.processing ? 'Mengunggah…' : 'Tambah berkas lain' }}
            </span>
        </label>

        <p v-if="errorFor(null)" class="mt-2 text-sm text-danger" role="alert">
            {{ errorFor(null) }}
        </p>
    </section>
</template>
